<?php

namespace Tests\Feature;

use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Militar;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Decisões do responsável (2026-11-18):
 * 1. Registrar ENTRADA é da gestão: o armeiro recebe 403 (movimento ENTRADA e importação de estoque); o gestor continua.
 * 2. Depósito só para a gestão (`estoque.depositos`): o armeiro não vê locais tipo DEPOSITO em lista nenhuma e recebe 403 ao operar neles.
 */
class DepositosSoGestaoTest extends TestCase
{
    use RefreshDatabase;

    private LocalEstoque $dep;

    private Usuario $armeiro;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->cenario();
        $this->dep = LocalEstoque::factory()->create(['unidade_id' => $this->c['alfa']->id, 'codigo' => 'DEP-T', 'nome' => 'Depósito fictício', 'tipo' => 'DEPOSITO']);
        $this->armeiro = $this->usuarioCom('ARMEIRO', $this->c['alfa']->id);
        // Estoque semeado pela gestão: reserva (localAlfa) e depósito.
        $this->como($this->c['gestorAlfa']);
        $this->entradaPelaGestao($this->entradaLote($this->c['localAlfa'], 10, 'L-RES'))->assertCreated();
        $this->entradaPelaGestao($this->entradaLote($this->dep, 99, 'L-DEP'))->assertCreated();
        $this->entradaPelaGestao($this->entradaIndividual($this->c['localAlfa'], 'SER-RES'))->assertCreated();
        $this->entradaPelaGestao($this->entradaIndividual($this->dep, 'SER-DEP'))->assertCreated();
    }

    private function papeisCom(string $perm): array
    {
        return DB::table('papel_permissao as pp')->join('papeis as p', 'p.id', '=', 'pp.papel_id')->join('permissoes as x', 'x.id', '=', 'pp.permissao_id')
            ->where('x.codigo', $perm)->orderBy('p.codigo')->pluck('p.codigo')->all();
    }

    private function mov(array $corpo): TestResponse
    {
        return $this->withHeaders($this->chave())->postJson('/api/v1/movements', $corpo);
    }

    private function locais(TestResponse $r, string $caminho = 'data'): array
    {
        return collect($r->assertOk()->json($caminho))->pluck('local.id')->unique()->sort()->values()->all();
    }

    public function test_matriz_entrada_so_gestao_e_depositos_para_gestores_para_cima(): void
    {
        $this->assertNotContains('ARMEIRO', $this->papeisCom('estoque.entrada'));
        $this->assertContains('GESTOR', $this->papeisCom('estoque.entrada'));
        $this->assertSame(['ADMIN', 'APROVADOR', 'AUDITOR', 'GESTOR'], $this->papeisCom('estoque.depositos'));
    }

    public function test_armeiro_recebe_403_em_entrada_e_importacao_de_estoque_gestor_continua(): void
    {
        $this->como($this->armeiro);
        $this->mov($this->entradaLote($this->c['localAlfa'], 5, 'L-ARM'))->assertForbidden();
        $csv = UploadedFile::fake()->createWithContent('estoque.csv', "\xEF\xBB\xBFlocal_codigo;documento;origem;material_codigo;numero_serie\n");
        $this->post('/api/v1/data-transfer/estoque/import', ['simulacao' => 1, 'arquivo' => $csv], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertFalse(in_array('estoque.entrada', $this->getJson('/api/v1/me')->assertOk()->json('permissoes'), true));

        $this->como($this->c['gestorAlfa']);
        $this->mov($this->entradaLote($this->c['localAlfa'], 5, 'L-GES'))->assertCreated();
    }

    public function test_armeiro_ainda_atualiza_conservacao_por_estoque_editar(): void
    {
        $item = ItemIndividual::where('numero_serie', 'SER-RES')->firstOrFail();
        $this->como($this->armeiro);
        $linha = collect($this->getJson('/api/v1/stock/individual-items')->assertOk()->json('data'))->firstWhere('id', $item->id);
        $this->assertTrue($linha['pode_atualizar_conservacao']);
        $this->patchJson("/api/v1/stock/individual-items/{$item->id}/conservation", ['estado_conservacao' => 'REGULAR', 'justificativa' => 'Desgaste visível na alça'])->assertOk();
    }

    public function test_armeiro_nao_ve_deposito_em_saldos_itens_locais_material_e_movimentacao(): void
    {
        $res = $this->c['localAlfa']->id;
        $this->como($this->armeiro);
        $this->assertSame([$res], $this->locais($this->getJson('/api/v1/stock/balances?per_page=100')));
        $this->getJson("/api/v1/stock/balances?local_id={$this->dep->id}")->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame([$res], $this->locais($this->getJson('/api/v1/stock/individual-items?per_page=100')));
        $ids = collect($this->getJson('/api/v1/locations')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($res, $ids);
        $this->assertNotContains($this->dep->id, $ids);
        $this->assertSame([$res], $this->locais($this->getJson("/api/v1/stock/materials/{$this->c['matLote']->id}"), 'saldos'));
        $this->assertSame(10, $this->getJson("/api/v1/stock/materials/{$this->c['matLote']->id}")->json('total.quantidade'));
        $entradaDep = DB::table('movimentacoes')->where('local_destino_id', $this->dep->id)->value('id');
        $this->getJson("/api/v1/movements/$entradaDep")->assertForbidden();
        $this->assertSame($res, $this->getJson('/api/v1/stock/delivery-options')->assertOk()->json('local_origem_padrao_id'));

        // Gestor vê os dois.
        $this->como($this->c['gestorAlfa']);
        $this->assertSame([$res, $this->dep->id], $this->locais($this->getJson('/api/v1/stock/balances?per_page=100')));
        $this->assertContains($this->dep->id, collect($this->getJson('/api/v1/locations')->json('data'))->pluck('id'));
        $this->assertSame(109, $this->getJson("/api/v1/stock/materials/{$this->c['matLote']->id}")->json('total.quantidade'));
        $this->getJson("/api/v1/movements/$entradaDep")->assertOk();
    }

    public function test_painel_e_alertas_contam_so_o_que_o_armeiro_ve(): void
    {
        DB::table('lotes')->where('codigo', 'L-DEP')->update(['validade' => now()->subDay()->toDateString()]);
        $this->como($this->armeiro);
        $outros = fn (TestResponse $r) => collect($r->assertOk()->json('grupos'))->sum('disponiveis');
        $this->assertSame(11, $outros($this->getJson('/api/v1/dashboard/materials'))); // 10 do lote + 1 item da reserva
        $this->assertSame(2, $this->getJson('/api/v1/dashboard')->assertOk()->json('estoque.locais')); // reservas de ALFA e ALFA-1, sem o depósito
        $this->assertNotContains('LOTE_VENCIDO', collect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->pluck('tipo'));

        $this->como($this->c['gestorAlfa']);
        $this->assertSame(111, $outros($this->getJson('/api/v1/dashboard/materials')));
        $this->assertSame(3, $this->getJson('/api/v1/dashboard')->json('estoque.locais'));
        $this->assertContains('LOTE_VENCIDO', collect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->pluck('tipo'));
    }

    public function test_armeiro_recebe_403_ao_operar_no_deposito_gestor_opera(): void
    {
        $efetivo = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'categoria_efetivo' => 'R1']);
        $loteDep = (int) DB::table('lotes')->where('codigo', 'L-DEP')->value('id');
        $itemDep = ItemIndividual::where('numero_serie', 'SER-DEP')->value('id');
        $entrega = fn () => $this->mov(['tipo' => 'ENTREGA', 'local_origem_id' => $this->dep->id, 'militar_id' => $efetivo->id, 'previsao_retorno' => now()->addDays(2)->toDateString(),
            'motivo_codigo' => 'PATRULHA', 'itens' => [['lote_id' => $loteDep, 'quantidade' => 1]]]);

        $this->como($this->armeiro);
        $entrega()->assertForbidden()->assertJsonPath('error.code', 'DEPOSITO_SO_GESTAO');
        $this->mov(['tipo' => 'TRANSFERENCIA', 'local_origem_id' => $this->c['localAlfa']->id, 'local_destino_id' => $this->dep->id, 'documento_referencia' => 'DOC-1',
            'justificativa' => 'Teste de transferência', 'itens' => [['lote_id' => (int) DB::table('lotes')->where('codigo', 'L-RES')->value('id'), 'quantidade' => 1]]])->assertForbidden();
        $this->withHeaders($this->chave())->postJson('/api/v1/stock/adjustments', ['local_id' => $this->dep->id, 'lote_id' => $loteDep, 'quantidade' => 98, 'motivo' => 'Ajuste de teste no depósito'])->assertForbidden();
        $this->patchJson("/api/v1/stock/individual-items/$itemDep", ['tamanho' => 'G', 'motivo' => 'Correção de teste no depósito'])->assertForbidden();
        $this->patchJson("/api/v1/stock/individual-items/$itemDep/conservation", ['estado_conservacao' => 'REGULAR', 'justificativa' => 'Desgaste de teste'])->assertForbidden();
        $this->patchJson("/api/v1/stock/lots/$loteDep", ['validade' => now()->addYear()->toDateString(), 'motivo' => 'Correção de teste no depósito'])->assertForbidden();

        // Gestor entrega a partir do depósito; a devolução volta ao depósito e o armeiro não pode registrá-la.
        $this->como($this->c['gestorAlfa']);
        $e = $entrega()->assertCreated()->json('id');
        $this->como($this->armeiro);
        $this->assertNotContains($e, collect($this->getJson('/api/v1/stock/linked')->assertOk()->json('data'))->pluck('entrega_id')->all());
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e])->assertForbidden();
        $this->como($this->c['gestorAlfa']);
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e])->assertCreated();
    }

    public function test_relatorio_de_entregas_do_armeiro_ignora_o_deposito(): void
    {
        $efetivo = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'categoria_efetivo' => 'R1']);
        $corpo = fn (LocalEstoque $l, string $lote, int $q) => ['tipo' => 'ENTREGA', 'local_origem_id' => $l->id, 'militar_id' => $efetivo->id, 'motivo_codigo' => 'PATRULHA',
            'itens' => [['lote_id' => (int) DB::table('lotes')->where('codigo', $lote)->value('id'), 'quantidade' => $q]]];
        $this->como($this->c['gestorAlfa']);
        $this->mov($corpo($this->dep, 'L-DEP', 7))->assertCreated();
        $this->como($this->armeiro);
        $this->mov($corpo($this->c['localAlfa'], 'L-RES', 2))->assertCreated();
        $total = fn () => collect($this->getJson('/api/v1/reports/deliveries-returns')->assertOk()->json('totais'))->sum('entregues');
        $this->assertSame(2, $total());
    }
}
