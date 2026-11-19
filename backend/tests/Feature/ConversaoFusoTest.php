<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use App\Services\AuditoriaService;
use App\Services\ConversaoFusoService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\LimpaBancoAposTeste;
use Tests\TestCase;

/**
 * ADR-015 — conversão do histórico gravado em UTC para America/Maceio (-3 h).
 * A conversão faz DDL (remove e recria gatilhos de imutabilidade): commit implícito, por isso não usa RefreshDatabase.
 * O "app antigo" é simulado gravando com o PHP em UTC e a sessão MySQL no fuso antigo do servidor.
 */
class ConversaoFusoTest extends TestCase
{
    protected $exceptTables = ['auditoria_cadeia'];

    use DatabaseTruncation, LimpaBancoAposTeste;

    private AuditoriaService $aud;

    private ConversaoFusoService $conv;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('eventos_auditoria')->truncate();
        DB::table(ConversaoFusoService::TABELA_MARCA)->truncate();
        DB::table('auditoria_cadeia')->updateOrInsert(['id' => 1], ['ultimo_hash' => str_repeat('0', 64)]);
        $this->aud = app(AuditoriaService::class);
        $this->conv = app(ConversaoFusoService::class);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('America/Maceio');
        DB::statement("SET time_zone = '-03:00'");
        $this->limparBanco();
        parent::tearDown();
    }

    /** Grava como o app antigo: PHP em UTC e sessão MySQL no fuso padrão do servidor ($sessao). Devolve os ids. */
    private function gravarComoAppAntigo(string $sessao): array
    {
        date_default_timezone_set('UTC');
        DB::statement('SET time_zone = ?', [$sessao]);
        try {
            $u = DB::table('usuarios')->insertGetId(['nome' => 'Sintético', 'login' => 'sint.fuso', 'password' => 'x',
                'created_at' => '2026-11-19 17:46:00', 'updated_at' => '2026-11-19 17:46:00', 'ultimo_login_em' => '2026-11-20 01:30:00']);
            $m = DB::table('movimentacoes')->insertGetId(['tipo' => 'ENTRADA', 'responsavel_id' => $u, 'ocorrido_em' => '2026-11-19 17:46:00',
                'chave_idempotencia' => 'fuso-1', 'hash_requisicao' => str_repeat('a', 64), 'created_at' => '2026-11-19 17:46:05']);
            $this->aud->registrar('teste.antigo.um', dados: ['n' => 1]);
            $this->aud->registrar('teste.antigo.dois', dados: ['n' => 2]);
        } finally {
            date_default_timezone_set('America/Maceio');
            DB::statement("SET time_zone = '-03:00'");
        }

        return [$u, $m];
    }

    private function cru(string $sql, array $b = []): object
    {
        return DB::selectOne($sql, $b);
    }

    public function test_servidor_em_menos_tres_converte_datetime_e_timestamp_para_hora_local(): void
    {
        [$u, $m] = $this->gravarComoAppAntigo('-03:00'); // produção: @@global.time_zone = SYSTEM (-03)
        $antes = EventoAuditoria::orderBy('id')->get()->map(fn ($e) => $e->getRawOriginal('ocorrido_em'))->all();
        $this->assertNull($this->aud->verificar());

        $r = $this->conv->executar(false, -10800);
        $this->assertSame('CONCLUIDA', $r['estado']);

        $this->assertSame('2026-11-19 14:46:00', $this->cru('SELECT LEFT(ocorrido_em, 19) v FROM movimentacoes WHERE id = ?', [$m])->v);
        $this->assertSame('2026-11-19 14:46:05', $this->cru('SELECT LEFT(created_at, 19) v FROM movimentacoes WHERE id = ?', [$m])->v);
        $this->assertSame('2026-11-19 14:46:00', $this->cru('SELECT created_at v FROM usuarios WHERE id = ?', [$u])->v);
        $this->assertSame('2026-11-19 22:30:00', $this->cru('SELECT ultimo_login_em v FROM usuarios WHERE id = ?', [$u])->v);
        // Instante preservado: o TIMESTAMP em UTC volta a ser o horário verdadeiro.
        $this->assertSame('2026-11-19 17:46:00', $this->cru("SELECT CONVERT_TZ(created_at, '-03:00', '+00:00') v FROM usuarios WHERE id = ?", [$u])->v);

        $depois = EventoAuditoria::where('acao', 'like', 'teste.antigo.%')->orderBy('id')->get();
        foreach ($depois as $i => $e) {
            $this->assertSame(
                CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', str_pad($antes[$i], 26, '.000000'), 'UTC')->subHours(3)->format('Y-m-d H:i:s.u'),
                CarbonImmutable::createFromFormat('Y-m-d H:i:s.u', str_pad($e->getRawOriginal('ocorrido_em'), 26, '.000000'), 'UTC')->format('Y-m-d H:i:s.u'));
        }

        // Cadeia continua íntegra: corte encadeado no evento de conversão.
        $marco = EventoAuditoria::where('acao', ConversaoFusoService::ACAO)->sole();
        $this->assertSame($depois->last()->id, $marco->dados['corte_evento_id']);
        $this->assertSame(-10800, $marco->dados['deslocamento_segundos']);
        $this->assertContains('trg_movimentacoes_no_update', $marco->dados['gatilhos_recriados']);
        $this->assertNull($this->aud->verificar());
        $this->artisan('sigarb:auditoria:verificar')->assertExitCode(0);

        // Eventos novos, já em hora local, seguem verificáveis.
        $this->aud->registrar('teste.novo');
        $this->assertNull($this->aud->verificar());
    }

    public function test_servidor_em_utc_so_desloca_datetime(): void
    {
        [$u, $m] = $this->gravarComoAppAntigo('+00:00'); // ambiente de desenvolvimento (MySQL em UTC)
        $this->conv->executar(false, 0);
        $this->assertSame('2026-11-19 14:46:00', $this->cru('SELECT LEFT(ocorrido_em, 19) v FROM movimentacoes WHERE id = ?', [$m])->v);
        // TIMESTAMP já estava certo no instante; lido na sessão -03:00 sai em hora local.
        $this->assertSame('2026-11-19 14:46:00', $this->cru('SELECT created_at v FROM usuarios WHERE id = ?', [$u])->v);
        $this->assertNull($this->aud->verificar());
    }

    public function test_rodar_duas_vezes_nao_subtrai_seis_horas(): void
    {
        [, $m] = $this->gravarComoAppAntigo('-03:00');
        $this->conv->executar(false, -10800);
        $this->assertSame('JA_CONCLUIDA', $this->conv->executar(false, -10800)['estado']);
        $this->assertSame('2026-11-19 14:46:00', $this->cru('SELECT LEFT(ocorrido_em, 19) v FROM movimentacoes WHERE id = ?', [$m])->v);
        $this->assertSame(1, EventoAuditoria::where('acao', ConversaoFusoService::ACAO)->count());
        $this->assertNull($this->aud->verificar());
    }

    public function test_retomada_apos_queda_nao_reconverte_tabela_e_recria_gatilhos(): void
    {
        [, $m] = $this->gravarComoAppAntigo('-03:00');
        $this->conv->executar(false, -10800);
        // Simula queda entre o commit de uma tabela e a recriação dos gatilhos: gatilho some e a marca global não existe.
        DB::unprepared('DROP TRIGGER IF EXISTS trg_movimentacoes_no_update');
        DB::table(ConversaoFusoService::TABELA_MARCA)->where('tabela', ConversaoFusoService::GLOBAL)->delete();

        $this->conv->executar(false, -10800);
        $this->assertSame('2026-11-19 14:46:00', $this->cru('SELECT LEFT(ocorrido_em, 19) v FROM movimentacoes WHERE id = ?', [$m])->v);
        $this->assertNotNull($this->cru("SELECT COUNT(*) v FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_movimentacoes_no_update'")->v ?: null);
        $this->expectExceptionMessage('razao imutavel');
        DB::table('movimentacoes')->where('id', $m)->update(['ocorrido_em' => now()]);
    }

    public function test_retomada_recusa_definicao_de_gatilho_adulterada(): void
    {
        $this->gravarComoAppAntigo('-03:00');
        // Queda no meio: marca EM_ANDAMENTO com a definição guardada. Credencial de runtime troca o corpo por um no-op.
        DB::table(ConversaoFusoService::TABELA_MARCA)->insert(['tabela' => 'movimentacoes', 'estado' => 'EM_ANDAMENTO', 'linhas' => 0,
            'gatilhos' => json_encode([['nome' => 'trg_movimentacoes_no_update', 'momento' => 'BEFORE', 'corpo' => 'SET @x = 1']]),
            'gatilhos_mac' => str_repeat('0', 64), 'atualizado_em' => now()->format('Y-m-d H:i:s.u')]);
        try {
            $this->conv->executar(false, -10800);
            $this->fail('deveria recusar a definição adulterada');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('adulterada', $e->getMessage());
        }
        $this->assertStringContainsString('razao imutavel', (string) DB::selectOne("SELECT ACTION_STATEMENT s FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_movimentacoes_no_update'")->s);
    }

    public function test_gatilhos_de_imutabilidade_continuam_ativos_depois(): void
    {
        [, $m] = $this->gravarComoAppAntigo('-03:00');
        $antes = DB::select('SELECT TRIGGER_NAME n, ACTION_STATEMENT s FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        $this->conv->executar(false, -10800);
        $depois = DB::select('SELECT TRIGGER_NAME n, ACTION_STATEMENT s FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        $this->assertEquals($antes, $depois);
        $this->expectExceptionMessage('auditoria append-only');
        DB::table('eventos_auditoria')->where('id', 1)->update(['acao' => 'x']);
    }

    public function test_corte_adulterado_e_detectado(): void
    {
        $this->gravarComoAppAntigo('-03:00');
        $this->conv->executar(false, -10800);
        $marco = EventoAuditoria::where('acao', ConversaoFusoService::ACAO)->sole();
        DB::unprepared('DROP TRIGGER IF EXISTS trg_auditoria_no_update');
        try {
            // Ator com DDL tenta "desconverter" o primeiro evento mudando o corte: o hash do marco denuncia.
            $d = $marco->dados;
            $d['corte_evento_id'] = $d['corte_evento_id'] - 1;
            DB::table('eventos_auditoria')->where('id', $marco->id)->update(['dados' => json_encode($d)]);
            $this->assertNotNull($this->aud->verificar());
        } finally {
            DB::unprepared("CREATE TRIGGER trg_auditoria_no_update BEFORE UPDATE ON eventos_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: auditoria append-only'");
        }
    }

    public function test_banco_novo_so_recebe_a_marca(): void
    {
        [, $m] = $this->gravarComoAppAntigo('-03:00');
        $this->assertSame('BANCO_NOVO', $this->conv->executar(true)['estado']);
        $this->assertSame('2026-11-19 17:46:00', $this->cru('SELECT LEFT(ocorrido_em, 19) v FROM movimentacoes WHERE id = ?', [$m])->v);
        $this->assertSame('JA_CONCLUIDA', $this->conv->executar(false, -10800)['estado']);
        $this->assertSame(0, EventoAuditoria::where('acao', ConversaoFusoService::ACAO)->count());
    }

    public function test_migracao_detecta_banco_novo_pelo_migrate_do_mesmo_processo(): void
    {
        ConversaoFusoService::$migracaoObservada = true;
        ConversaoFusoService::$migracoesNesteProcesso = [ConversaoFusoService::MIGRACAO_INICIAL, '2026_12_01_000100_fuso_america_maceio'];
        $this->assertTrue(ConversaoFusoService::bancoNovo());
        ConversaoFusoService::$migracoesNesteProcesso = ['2026_12_01_000100_fuso_america_maceio'];
        $this->assertFalse(ConversaoFusoService::bancoNovo());
        ConversaoFusoService::$migracaoObservada = false;
        $this->expectException(\RuntimeException::class);
        ConversaoFusoService::bancoNovo();
    }
}
