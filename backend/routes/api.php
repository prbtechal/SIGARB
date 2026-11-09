<?php

use App\Http\Controllers\Api\AcautelamentoController;
use App\Http\Controllers\Api\AlertaController;
use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogoController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\EstoqueController;
use App\Http\Controllers\Api\ImportacaoController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\MilitarController;
use App\Http\Controllers\Api\MilitarUnidadeController;
use App\Http\Controllers\Api\OperacoesController;
use App\Http\Controllers\Api\OrganizacaoController;
use App\Http\Controllers\Api\ReferenciaController;
use App\Http\Controllers\Api\RelatorioController;
use App\Http\Controllers\Api\SuporteController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\VinculoMaterialController;
use App\Http\Middleware\UsuarioAtivo;
use App\Http\Controllers\Api\ConfiguracaoController;
use App\Support\Operacoes;
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
    Route::get('militares/{militar}/foto', [MilitarController::class, 'foto']);
    Route::post('militares/{militar}/foto', [MilitarController::class, 'enviarFoto'])->middleware('throttle:10,1');
    Route::delete('militares/{militar}/foto', [MilitarController::class, 'removerFoto']);

    Route::get('catalog/{tipo}', [CatalogoController::class, 'referencias'])->whereIn('tipo', ['categorias', 'fabricantes', 'calibres', 'postos-graduacoes', 'tipos-material', 'niveis-protecao']);
    // Escrita de listas de referência SOMENTE por /reference-lists (referencias.gerir global). Rota antiga removida no CP-6.
    Route::get('items', [CatalogoController::class, 'materiais']);
    Route::post('items', [CatalogoController::class, 'criarMaterial']);
    Route::get('items/{material}', [CatalogoController::class, 'material']);
    Route::put('items/{material}', [CatalogoController::class, 'atualizarMaterial']);

    Route::get('stock/balances', [EstoqueController::class, 'saldos']);
    Route::get('stock/individual-items', [EstoqueController::class, 'itensIndividuais']);
    Route::patch('stock/individual-items/{item}/conservation', [EstoqueController::class, 'atualizarConservacao'])->whereNumber('item');
    Route::get('stock/linked', [VinculoMaterialController::class, 'index']); // CP-12 — RF-VIN-06
    Route::get('militares/{militar}/secondary-units', [MilitarUnidadeController::class, 'index']);
    Route::post('militares/{militar}/secondary-units', [MilitarUnidadeController::class, 'store']);
    Route::post('militares/{militar}/secondary-units/{vinculo}/end', [MilitarUnidadeController::class, 'encerrar'])->whereNumber('vinculo');
    Route::get('movements', [EstoqueController::class, 'movimentacoes']);
    Route::post('movements', [EstoqueController::class, 'registrar']);
    Route::get('movements/{movimentacao}', [EstoqueController::class, 'movimentacao']);
    Route::post('movements/{movimentacao}/reversal', [EstoqueController::class, 'estornar']);

    Route::get('audit-events', [AuditoriaController::class, 'index']);
    Route::get('audit-events/integrity', [AuditoriaController::class, 'integridade']);

    // CP-16 — ajustes do sistema (Administrador).
    Route::get('system-settings', [ConfiguracaoController::class, 'index']);
    Route::put('system-settings/{chave}', [ConfiguracaoController::class, 'update'])->where('chave', '[a-z_.A-Z]+')->middleware('throttle:30,1');
    Route::delete('system-settings/{chave}', [ConfiguracaoController::class, 'restaurar'])->where('chave', '[a-z_.A-Z]+')->middleware('throttle:30,1');

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
    Route::post('transfers', fn (Request $r) => Operacoes::estado('TRANSFERENCIA') === 'BLOQUEADA'
        ? app(OperacoesController::class)->bloqueada('TRANSFERENCIA')
        : app(EstoqueController::class)->registrar($r->merge(['tipo' => 'TRANSFERENCIA'])));
    // RF-015..018 — acautelamento com regra provisória (portão checado no serviço).
    $ulid = '[0-9A-HJKMNP-TV-Z]{26}';
    Route::get('acautelamentos', [AcautelamentoController::class, 'index']);
    Route::post('acautelamentos/requests', [AcautelamentoController::class, 'solicitar'])->middleware('throttle:20,1');
    Route::get('acautelamentos/{codigo}', [AcautelamentoController::class, 'show'])->where('codigo', $ulid);
    Route::post('acautelamentos/{codigo}/decision', [AcautelamentoController::class, 'decidir'])->where('codigo', $ulid);
    Route::post('acautelamentos/{codigo}/cancel', [AcautelamentoController::class, 'cancelar'])->where('codigo', $ulid);
    Route::post('acautelamentos/{codigo}/delivery', [AcautelamentoController::class, 'entregar'])->where('codigo', $ulid);
    Route::post('acautelamentos/{codigo}/inspections', [AcautelamentoController::class, 'inspecionar'])->where('codigo', $ulid);
    Route::post('acautelamentos/{codigo}/return', [AcautelamentoController::class, 'desacautelar'])->where('codigo', $ulid);
    Route::get('acautelamentos/{codigo}/carteira', [AcautelamentoController::class, 'carteira'])->where('codigo', $ulid);
    Route::get('carteiras/{token}', [AcautelamentoController::class, 'verificar'])->where('token', '[A-Za-z0-9]{43}')->middleware('throttle:30,1');
    // RF-022 — alertas calculados (prazos provisórios). RF-023 — exportação CSV provisória (não oficial, P-12).
    Route::get('alerts', [AlertaController::class, 'index']);
    // CP-13 — importação/exportação de cadastros por planilha CSV.
    Route::get('data-transfer', [ImportacaoController::class, 'index']);
    Route::get('data-transfer/{entidade}/template', [ImportacaoController::class, 'modelo'])->where('entidade', '[a-z-]+');
    Route::post('data-transfer/{entidade}/import', [ImportacaoController::class, 'importar'])->where('entidade', '[a-z-]+')->middleware('throttle:20,1');
    Route::get('data-transfer/{entidade}/export', [ImportacaoController::class, 'exportar'])->where('entidade', '[a-z-]+')->middleware('throttle:10,1');
    Route::get('reports', [RelatorioController::class, 'index']);
    Route::get('reports/{chave}', [RelatorioController::class, 'exportar'])->where('chave', '[a-z]+')->middleware('throttle:10,1');
    // RF-020 — inventário (regra provisória).
    Route::get('inventories', [InventarioController::class, 'index']);
    Route::post('inventories', [InventarioController::class, 'store']);
    Route::get('inventories/{codigo}', [InventarioController::class, 'show'])->where('codigo', $ulid);
    Route::put('inventories/{codigo}/counts', [InventarioController::class, 'contar'])->where('codigo', $ulid);
    Route::post('inventories/{codigo}/submit', [InventarioController::class, 'enviar'])->where('codigo', $ulid);
    Route::post('inventories/{codigo}/cancel', [InventarioController::class, 'cancelar'])->where('codigo', $ulid);
    Route::post('inventories/{codigo}/decision', [InventarioController::class, 'decidir'])->where('codigo', $ulid);
});
