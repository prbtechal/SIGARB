<?php

// Processo independente (conexão MySQL própria) usado por ConcorrenciaTest. Somente testes.
use App\Models\Chamado;
use App\Models\Movimentacao;
use App\Models\Usuario;
use App\Services\DocumentoService;
use App\Services\EstoqueService;
use App\Services\SuporteService;
use App\Support\SigarbException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $op, $usuarioId, $payload, $chave, $inicio] = $argv;
$d = json_decode($payload, true);
$u = Usuario::findOrFail($usuarioId);
auth()->setUser($u);
while (microtime(true) < (float) $inicio) {
    usleep(100);
}
try {
    $svc = app(EstoqueService::class);
    $res = match ($op) {
        'entrada' => $svc->registrarEntrada($u, $d, $chave),
        'estorno' => $svc->estornar($u, Movimentacao::findOrFail($d['id']), 'Estorno concorrente de teste', $chave),
        'documento' => [app(DocumentoService::class)->armazenar($u,
            new UploadedFile($d['arquivo'], 'mesmo-nome.pdf', null, null, true), $d), false],
        'debito' => [DB::transaction(fn () => $svc->debitarLote($d['local'], $d['lote'], $d['qtd']), 3), false],
        'suporte' => [app(SuporteService::class)->atualizar($u, Chamado::where('codigo', $d['codigo'])->firstOrFail(), $d['dados']), false],
    };
    echo json_encode(['ok' => true, 'replay' => $res[1] ?? false]);
} catch (SigarbException $e) {
    echo json_encode(['ok' => false, 'code' => $e->codigo]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'EXCECAO', 'msg' => $e->getMessage()]);
}
