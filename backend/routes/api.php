<?php

use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\EstoqueController;
use App\Http\Controllers\Api\MilitarController;
use App\Http\Controllers\Api\OperacoesController;
use App\Http\Controllers\Api\OrganizacaoController;
use App\Http\Controllers\Api\ReferenciaController;
use App\Http\Controllers\Api\SuporteController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Middleware\UsuarioAtivo;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Prefixo /api/v1, grupo "web" (sessão + CSRF) — ver ADR-003. Contrato: docs/api/openapi.yaml.
Route::get('csrf', [AuthController::class, 'csrf']);
// RNF-09 — sondas de saúde: routes/saude.php (fora do grupo web, CP-8).
Route::post('auth/login', [AuthController::class, 'login']);

Route::middleware(['auth', UsuarioAtivo::class])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::get('me/sessions', [AuthController::class, 'sessoes']);
    Route::delete('me/sessions/{ref}', [AuthController::class, 'revogarSessao'])->where('ref', '[a-f0-9]{16}');
    Route::get('dashboard', DashboardController::class);
    Route::get('operations', [OperacoesController::class, 'index']);

    Route::get('roles', [UsuarioController::class, 'papeis']);
    Route::get('users', [UsuarioController::class, 'index']);
    Route::post('users', [UsuarioController::class, 'store']);
    Route::get('users/{usuario}', [UsuarioController::class, 'show']);
    Route::put('users/{usuario}', [UsuarioController::class, 'update']);
    Route::post('users/{usuario}/roles', [UsuarioController::class, 'atribuirPapel']);
    Route::delete('users/{usuario}/roles/{atribuicao}', [UsuarioController::class, 'removerPapel']);
    Route::post('users/{usuario}/sessions/revoke', [UsuarioController::class, 'revogarSessoes']);

    Route::get('organizations', [OrganizacaoController::class, 'organizacoes']);
    Route::post('organizations', [OrganizacaoController::class, 'criarOrganizacao']);
    Route::get('units', [OrganizacaoController::class, 'unidades']);
    Route::post('units', [OrganizacaoController::class, 'criarUnidade']);
    Route::put('units/{unidade}', [OrganizacaoController::class, 'atualizarUnidade']);
    Route::get('locations', [OrganizacaoController::class, 'locais']);
    Route::post('locations', [OrganizacaoController::class, 'criarLocal']);
    Route::put('locations/{local}', [OrganizacaoController::class, 'atualizarLocal']);

    Route::get('militares', [MilitarController::class, 'index']);
    Route::post('militares', [MilitarController::class, 'store']);
    Route::get('militares/{militar}', [MilitarController::class, 'show']);
    Route::put('militares/{militar}', [MilitarController::class, 'update']);

    Route::get('catalog/{tipo}', [CatalogoController::class, 'referencias'])->whereIn('tipo', ['categorias', 'fabricantes', 'calibres', 'postos-graduacoes']);
    // Escrita de listas de referência SOMENTE por /reference-lists (referencias.gerir global). Rota antiga removida no CP-6.
    Route::get('items', [CatalogoController::class, 'materiais']);
    Route::post('items', [CatalogoController::class, 'criarMaterial']);
    Route::get('items/{material}', [CatalogoController::class, 'material']);
    Route::put('items/{material}', [CatalogoController::class, 'atualizarMaterial']);

    Route::get('stock/balances', [EstoqueController::class, 'saldos']);
    Route::get('stock/individual-items', [EstoqueController::class, 'itensIndividuais']);
    Route::get('movements', [EstoqueController::class, 'movimentacoes']);
    Route::post('movements', [EstoqueController::class, 'registrar']);
    Route::get('movements/{movimentacao}', [EstoqueController::class, 'movimentacao']);
    Route::post('movements/{movimentacao}/reversal', [EstoqueController::class, 'estornar']);

    Route::get('audit-events', [AuditoriaController::class, 'index']);
    Route::get('audit-events/integrity', [AuditoriaController::class, 'integridade']);

    // RF-029 — administração de listas de referência (global, sem exclusão).
    Route::get('reference-lists', [ReferenciaController::class, 'listas']);
    Route::get('reference-lists/{tipo}', [ReferenciaController::class, 'index']);
    Route::post('reference-lists/{tipo}', [ReferenciaController::class, 'store']);
    Route::put('reference-lists/{tipo}/{id}', [ReferenciaController::class, 'update'])->whereNumber('id');
    Route::post('reference-lists/{tipo}/{id}/deactivate', [ReferenciaController::class, 'desativar'])->whereNumber('id');
    Route::post('reference-lists/{tipo}/{id}/reactivate', [ReferenciaController::class, 'reativar'])->whereNumber('id');

    // RF-014 — documentos privados (ADR-011). {codigo} = ULID público.
    Route::get('document-types', [DocumentoController::class, 'tipos']);
    // Central de Suporte (RF-SUP, CP-9). Rotas por código ULID; limite de taxa nas escritas.
    Route::get('support/tickets', [SuporteController::class, 'meus']);
    Route::post('support/tickets', [SuporteController::class, 'store'])->middleware('throttle:5,1');
    Route::get('support/queue', [SuporteController::class, 'fila']);
    Route::get('support/queue/summary', [SuporteController::class, 'resumoFila']);
    Route::get('support/tickets/{codigo}', [SuporteController::class, 'show'])->where('codigo', '[0-9A-HJKMNP-TV-Z]{26}');
    Route::post('support/tickets/{codigo}/messages', [SuporteController::class, 'responder'])->where('codigo', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:20,1');
    Route::patch('support/tickets/{codigo}', [SuporteController::class, 'atualizar'])->where('codigo', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:60,1');
    Route::post('support/tickets/{codigo}/reopen', [SuporteController::class, 'reabrir'])->where('codigo', '[0-9A-HJKMNP-TV-Z]{26}')->middleware('throttle:10,1');
    Route::get('support/tickets/{codigo}/agents', [SuporteController::class, 'atendentes'])->where('codigo', '[0-9A-HJKMNP-TV-Z]{26}');
    Route::get('support/tickets/{codigo}/history', [SuporteController::class, 'historico'])->where('codigo', '[0-9A-HJKMNP-TV-Z]{26}');
    Route::get('notifications', [SuporteController::class, 'notificacoes']);
    Route::post('notifications/read-all', [SuporteController::class, 'marcarTodas']);
    Route::post('notifications/{id}/read', [SuporteController::class, 'marcarLida'])->whereNumber('id');
    Route::get('documents', [DocumentoController::class, 'index']);
    Route::post('documents', [DocumentoController::class, 'store'])->middleware('throttle:20,1');
    Route::get('documents/{codigo}', [DocumentoController::class, 'show']);
    Route::put('documents/{codigo}', [DocumentoController::class, 'update']);
    Route::get('documents/{codigo}/download', [DocumentoController::class, 'download']);
    Route::post('documents/{codigo}/archive', [DocumentoController::class, 'arquivar']);
    Route::post('documents/{codigo}/reactivate', [DocumentoController::class, 'reativar']);
    Route::post('documents/{codigo}/links', [DocumentoController::class, 'vincular']);

    // Rotas do SRS §11 cujas políticas estão pendentes (ADR-006): respondem 409 OPERACAO_BLOQUEADA.
    $bloq = fn (string $op) => fn () => app(OperacoesController::class)->bloqueada($op);
    Route::post('transfers', fn (Request $r) => Operacoes::estado('TRANSFERENCIA') === 'BLOQUEADA'
        ? app(OperacoesController::class)->bloqueada('TRANSFERENCIA')
        : app(EstoqueController::class)->registrar($r->merge(['tipo' => 'TRANSFERENCIA'])));
    Route::post('acautelamentos/requests', $bloq('ACAUTELAMENTO'));
    Route::post('acautelamentos/{id}/decision', $bloq('ACAUTELAMENTO'));
    Route::post('acautelamentos/{id}/delivery', $bloq('ACAUTELAMENTO'));
    Route::post('acautelamentos/{id}/inspections', $bloq('INSPECAO'));
    Route::post('acautelamentos/{id}/return', $bloq('DESACAUTELAMENTO'));
    Route::get('reports/{reportKey}', fn () => throw new SigarbException('RELATORIO_INDISPONIVEL', 'Relatórios oficiais dependem da decisão institucional P-12.', 409, ['pendencia' => 'P-12']));
    Route::get('alerts', fn () => throw new SigarbException('ALERTAS_INDISPONIVEIS', 'Regras de alerta dependem de P-03/RN-023.', 409, ['pendencia' => 'P-03']));
});
