<?php
/**
 * PROTÓTIPO PE-07 — NÃO usado pela aplicação. Executa sobre uma CÓPIA descartável da trilha (banco sigarb_proto_pe07).
 * Âncora = {ultimo_id, ultimo_hash, total, emitida_em} assinada com Ed25519 (chave privada FORA do banco).
 * Demonstra: reescrita completa da cadeia passa na verificação local, mas é detectada pela âncora.
 */
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\AuditoriaService;
use Illuminate\Support\Facades\DB;

$svc = app(AuditoriaService::class);
$par = sodium_crypto_sign_keypair();                 // em produção: chave em HSM/servidor separado
$pub = sodium_crypto_sign_publickey($par);
$sec = sodium_crypto_sign_secretkey($par);

$ancorar = function () use ($sec) {
    $u = DB::table('eventos_auditoria')->orderByDesc('id')->first(['id', 'hash']);
    $msg = json_encode(['ultimo_id' => $u->id, 'ultimo_hash' => $u->hash, 'total' => DB::table('eventos_auditoria')->count(), 'emitida_em' => date(DATE_ATOM)]);
    return ['msg' => $msg, 'sig' => base64_encode(sodium_crypto_sign_detached($msg, $sec))];
};
$conferir = function (array $a) use ($pub): string {
    if (! sodium_crypto_sign_verify_detached(base64_decode($a['sig']), $a['msg'], $pub)) return 'ÂNCORA COM ASSINATURA INVÁLIDA';
    $d = json_decode($a['msg'], true);
    $h = DB::table('eventos_auditoria')->where('id', $d['ultimo_id'])->value('hash');
    return hash_equals($d['ultimo_hash'], (string) $h) ? 'confere com a âncora' : 'DIVERGE DA ÂNCORA (adulteração detectada)';
};

$ancora = $ancorar();
$r = ['eventos' => DB::table('eventos_auditoria')->count(), 'antes' => ['cadeia_local' => $svc->verificar() === null ? 'íntegra' : 'quebrada', 'ancora' => $conferir($ancora)]];

// Ataque de DBA privilegiado: altera um evento e recalcula TODA a cadeia e o ponteiro.
$alvo = DB::table('eventos_auditoria')->orderBy('id')->skip(1)->value('id');
DB::table('eventos_auditoria')->where('id', $alvo)->update(['acao' => 'evento.forjado']);
$ant = AuditoriaService::GENESIS;
foreach (App\Models\EventoAuditoria::orderBy('id')->get() as $e) {
    $at = $e->getAttributes(); $at['dados'] = $e->dados; $at['ocorrido_em'] = $e->ocorrido_em->format('Y-m-d H:i:s.u');
    $novo = AuditoriaService::calcularHash($ant, $at);
    DB::table('eventos_auditoria')->where('id', $e->id)->update(['hash_anterior' => $ant, 'hash' => $novo]);
    $ant = $novo;
}
DB::table('auditoria_cadeia')->update(['ultimo_hash' => $ant]);
$r['depois_da_reescrita'] = ['cadeia_local' => $svc->verificar() === null ? 'íntegra (não detecta)' : 'quebrada', 'ancora' => $conferir($ancora)];

// Âncora forjada pelo atacante (sem a chave privada) também falha.
$f = $ancora; $m = json_decode($f['msg'], true); $m['ultimo_hash'] = $ant; $f['msg'] = json_encode($m);
$r['ancora_forjada_sem_chave'] = $conferir($f);
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
