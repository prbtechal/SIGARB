<?php

namespace Tests\Feature;

use App\Models\AtribuicaoPapel;
use App\Models\Categoria;
use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Movimentacao;
use App\Models\Papel;
use App\Models\TipoMaterial;
use App\Models\Unidade;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Decisões do responsável (2026-11-18): armeiro só na própria base, "Entrega de material" agrupada por entrega,
 * tela de devolução, sem as telas de gestão, relatório de entregas/devoluções e listas fechadas de documento/motivo.
 * Cenário: ALFA (raiz) ⊃ ALFA-1 (base do armeiro) e ALFA-2 (outra base); BRAVO separado.
 */
class ArmeiroBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->cenario();
        $this->c['alfa2'] = Unidade::factory()->sub($this->c['alfa'])->create(['sigla' => 'T-ALFA-2'])->fresh();
        $this->c['localAlfa2'] = LocalEstoque::factory()->create(['unidade_id' => $this->c['alfa2']->id, 'tipo' => 'RESERVA']);
        $this->c['depositoAlfa1'] = LocalEstoque::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'tipo' => 'DEPOSITO']);
        $this->c['armeiro'] = $this->usuarioCom('ARMEIRO', $this->c['alfa1']->id);
        $this->c['efetivo'] = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'PM_ATIVA',
            'nome_guerra' => 'BALCAO', 'matricula' => 'MAT-998877', 'cpf' => '52998224725']);
        $this->c['efetivoOutro'] = Militar::factory()->create(['unidade_id' => $this->c['alfa2']->id, 'categoria_efetivo' => 'PM_ATIVA', 'nome_guerra' => 'OUTRABASE']);
        $armas = Categoria::firstOrCreate(['codigo' => 'ARM-T'], ['nome' => 'Armas teste', 'natureza' => 'ARMAMENTO']);
        $tipo = TipoMaterial::create(['categoria_id' => $armas->id, 'nome' => 'Pistola teste', 'perfil' => 'ARMA_FOGO', 'ativo' => true]);
        $this->c['arma'] = Material::factory()->create(['categoria_id' => $armas->id, 'tipo_id' => $tipo->id, 'nome' => 'Pistola fictícia']);
        $mun = Categoria::firstOrCreate(['codigo' => 'MUN-T'], ['nome' => 'Munição teste', 'natureza' => 'MUNICAO']);
        $this->c['municao'] = Material::factory()->lote()->create(['categoria_id' => $mun->id, 'nome' => 'Munição fictícia']);
    }

    private function mov(array $corpo): TestResponse
    {
        return $this->withHeaders($this->chave())->postJson('/api/v1/movements', $corpo);
    }

    /** Estoque na base ALFA-1: pistola, colete (matInd), lote de munição; e um item na outra base. */
    private function estoque(): array
    {
        $this->como($this->c['gestorAlfa']);
        $l1 = $this->c['localAlfa1']->id;
        $this->mov(['tipo' => 'ENTRADA', 'local_destino_id' => $l1, 'origem_descricao' => 'Origem', 'documento_referencia' => 'DOC-1', 'itens' => [
            ['material_id' => $this->c['arma']->id, 'numero_serie' => 'PST-1'], ['material_id' => $this->c['matInd']->id, 'numero_serie' => 'COL-1'],
            ['material_id' => $this->c['municao']->id, 'lote_codigo' => 'MUN-L1', 'quantidade' => 100]]])->assertCreated();
        $this->mov($this->entradaIndividual($this->c['localAlfa2'], 'OUTRA-1'))->assertCreated();

        return ['arma' => ItemIndividual::where('numero_serie', 'PST-1')->value('id'), 'colete' => ItemIndividual::where('numero_serie', 'COL-1')->value('id'),
            'lote' => (int) DB::table('lotes')->where('codigo', 'MUN-L1')->value('id'), 'outra' => ItemIndividual::where('numero_serie', 'OUTRA-1')->value('id')];
    }

    private function entrega(array $ids, array $extra = []): TestResponse
    {
        return $this->mov(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa1']->id, 'militar_id' => $this->c['efetivo']->id,
            'previsao_retorno' => now()->addDays(2)->toDateString(), 'motivo_codigo' => 'PATRULHA', 'documento_codigo' => 'ESCALA',
            'itens' => [['item_individual_id' => $ids['arma']], ['item_individual_id' => $ids['colete']], ['lote_id' => $ids['lote'], 'quantidade' => 30]]] + $extra);
    }

    // ---------- 1. Armeiro só na própria base ----------

    public function test_armeiro_so_ve_a_propria_base_em_listas_e_selecoes(): void
    {
        $ids = $this->estoque();
        $this->como($this->c['gestorAlfa']);
        $this->mov(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa2']->id, 'militar_id' => $this->c['efetivoOutro']->id, 'motivo_codigo' => 'EVENTO',
            'previsao_retorno' => now()->addDay()->toDateString(), 'itens' => [['item_individual_id' => $ids['outra']]]])->assertCreated();

        $this->como($this->c['armeiro']);
        $this->entrega($ids)->assertCreated();
        $this->assertSame([$this->c['alfa1']->id], collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('id')->all());
        // Depósito só para a gestão (2026-11-18): o armeiro vê só a reserva da base.
        $this->assertEqualsCanonicalizing([$this->c['localAlfa1']->id], collect($this->getJson('/api/v1/locations')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertSame(['BALCAO'], collect($this->getJson('/api/v1/stock/linked')->json('data'))->pluck('militar.nome_guerra')->all());
        $this->assertNotContains('OUTRA-1', collect($this->getJson('/api/v1/stock/individual-items?per_page=100')->json('data'))->pluck('numero_serie')->all());
        $this->assertSame([$this->c['localAlfa1']->id], collect($this->getJson('/api/v1/stock/balances?per_page=100')->json('data'))->pluck('local.id')->unique()->values()->all());
        // Busca de efetivo do balcão: só a base.
        $this->getJson('/api/v1/stock/recipients?q=OUTRABASE')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stock/recipients?q=BALCAO')->assertOk()->assertJsonPath('data.0.id', $this->c['efetivo']->id)
            ->assertJsonPath('data.0.apto_arma_fogo', true)->assertJsonMissingPath('data.0.telefone_institucional');
        // Matrícula e CPF exatos (o armeiro mantém militares.ver_detalhe na base).
        $this->getJson('/api/v1/stock/recipients?q=MAT-998877')->assertJsonPath('data.0.id', $this->c['efetivo']->id);
        $this->getJson('/api/v1/stock/recipients?q=529.982.247-25')->assertJsonPath('data.0.id', $this->c['efetivo']->id);
        // Fora da base: entrega/devolução recusadas no servidor.
        $this->mov(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa2']->id, 'militar_id' => $this->c['efetivo']->id, 'motivo_codigo' => 'EVENTO',
            'itens' => [['item_individual_id' => $ids['outra']]]])->assertForbidden();
    }

    public function test_papel_armeiro_exige_base_abaixo_da_raiz(): void
    {
        $u = Usuario::factory()->create();
        $this->como($this->c['admin']);
        foreach ([null, $this->c['alfa']->id] as $escopo) {
            $this->postJson("/api/v1/users/{$u->id}/roles", ['papel' => 'ARMEIRO', 'unidade_id' => $escopo])->assertStatus(422)
                ->assertJsonPath('error.code', 'ESCOPO_BASE_OBRIGATORIO');
        }
        $this->assertSame(0, AtribuicaoPapel::where('usuario_id', $u->id)->count());
        $this->postJson("/api/v1/users/{$u->id}/roles", ['papel' => 'ARMEIRO', 'unidade_id' => $this->c['alfa1']->id])->assertCreated();
        // Outros papéis continuam aceitando escopo global.
        $this->postJson("/api/v1/users/{$u->id}/roles", ['papel' => 'AUDITOR', 'unidade_id' => null])->assertCreated();
    }

    // ---------- 3. Lista agrupada por entrega e busca do balcão ----------

    public function test_lista_agrupa_por_entrega_e_busca_por_numero_e_documento(): void
    {
        $ids = $this->estoque();
        $this->como($this->c['armeiro']);
        $e = $this->entrega($ids)->assertCreated()->json('id');
        $r = $this->getJson('/api/v1/stock/linked')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.entrega_id', $e)
            ->assertJsonPath('data.0.total_itens', 3)->assertJsonPath('data.0.quantidade_total', 32)->assertJsonPath('data.0.pode_receber', true);
        $this->assertSame(['BOM' => 2], $r->json('data.0.conservacao'));
        foreach (["#$e", (string) $e, 'BALCAO', 'MAT-998877', '52998224725', 'COL-1'] as $q) {
            $this->getJson('/api/v1/stock/linked?q='.urlencode($q))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_itens', 3);
        }
        $this->getJson('/api/v1/stock/linked?q=%23999999')->assertJsonPath('meta.total', 0);
        // Devolução parcial tira só o que voltou; a linha continua com o resto.
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e, 'itens' => [['item_individual_id' => $ids['colete'], 'estado_conservacao' => 'REGULAR']]])->assertCreated();
        $this->getJson('/api/v1/stock/linked')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_itens', 2);
        // Paginação por entrega: duas entregas = duas linhas.
        $this->como($this->c['gestorAlfa'])->mov(['tipo' => 'ENTRADA', 'local_destino_id' => $this->c['localAlfa1']->id, 'origem_descricao' => 'Origem', 'documento_referencia' => 'DOC-2',
            'itens' => [['material_id' => $this->c['matInd']->id, 'numero_serie' => 'COL-2']]])->assertCreated();
        $this->como($this->c['armeiro'])->mov(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa1']->id, 'militar_id' => $this->c['efetivo']->id,
            'previsao_retorno' => now()->addDay()->toDateString(), 'motivo_codigo' => 'TREINAMENTO', 'itens' => [['item_individual_id' => ItemIndividual::where('numero_serie', 'COL-2')->value('id')]]])->assertCreated();
        $this->getJson('/api/v1/stock/linked?per_page=1')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
    }

    public function test_busca_pelo_comprovante_do_pedido(): void
    {
        $ids = $this->estoque();
        $conta = Usuario::factory()->create(['militar_id' => $this->c['efetivo']->id]);
        AtribuicaoPapel::create(['usuario_id' => $conta->id, 'papel_id' => Papel::where('codigo', 'EFETIVO')->value('id'), 'unidade_id' => $this->c['alfa1']->id]);
        $this->como($conta);
        $p = $this->postJson('/api/v1/portal/requests', ['itens' => [['material_id' => $this->c['municao']->id, 'quantidade' => 5]]])->assertCreated();
        $cod = $p->json('codigo');
        $this->como($this->c['armeiro']);
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertOk();
        $this->withHeaders($this->chave())->postJson("/api/v1/material-requests/$cod/delivery", ['versao' => 2, 'local_id' => $this->c['localAlfa1']->id,
            'previsao_retorno' => now()->addDay()->toDateString(), 'linhas' => [['id' => $p->json('itens.0.id'), 'lotes' => [['lote_id' => $ids['lote'], 'quantidade' => 5]]]]])->assertCreated();
        $comprovante = 'PED-'.substr($cod, -6);
        $this->getJson('/api/v1/stock/linked?q='.$comprovante)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.pedido.comprovante', $comprovante);
        $this->getJson('/api/v1/stock/linked?q='.$cod)->assertJsonPath('meta.total', 1);
    }

    // ---------- 4. Telas de gestão: 403; fluxos do armeiro continuam ----------

    public function test_armeiro_recebe_403_nas_telas_de_gestao(): void
    {
        $this->como($this->c['armeiro']);
        foreach (['movements', 'movements?pendente=1', 'militares', "militares/{$this->c['efetivo']->id}", 'documents', 'inventories', 'organizations',
            'data-transfer', 'data-transfer/estoque/export', 'reports', 'audit-events'] as $rota) {
            $this->getJson("/api/v1/$rota")->assertForbidden();
        }
        // Cadastros (catálogo, unidades, efetivos): as policies negam; as rotas validam o corpo antes e respondem 403 com corpo válido.
        $g = Gate::forUser($this->c['armeiro']);
        $this->assertTrue($g->denies('create', Material::class) && $g->denies('update', $this->c['matInd']));
        $this->assertTrue($g->denies('create', [Unidade::class, $this->c['alfa1']->id]) && $g->denies('create', [Militar::class, $this->c['alfa1']->id]));
        $this->postJson('/api/v1/inventories', ['local_estoque_id' => $this->c['localAlfa1']->id])->assertForbidden();
        // Gestor continua com as telas.
        $this->como($this->c['gestorAlfa']);
        foreach (['movements', 'militares', 'documents', 'inventories', 'data-transfer', 'reports'] as $rota) {
            $this->getJson("/api/v1/$rota")->assertOk();
        }
        $perms = $this->como($this->c['armeiro'])->getJson('/api/v1/me')->json('permissoes');
        foreach (['militares.ver', 'catalogo.ver', 'organizacao.ver', 'documentos.ver', 'inventario.contar', 'dados.importar', 'relatorios.exportar', 'movimentacoes.ver', 'inventario.ver'] as $p) {
            $this->assertNotContains($p, $perms);
        }
        $this->assertContains('relatorios.entregas', $perms);
    }

    public function test_armeiro_continua_entregando_devolvendo_baixando_e_vendo_selecoes(): void
    {
        $ids = $this->estoque();
        $this->c['efetivo']->update(['foto_caminho' => null]);
        $this->como($this->c['armeiro']);
        $this->getJson('/api/v1/items?per_page=100')->assertOk();
        $this->getJson("/api/v1/items/{$this->c['matInd']->id}")->assertOk();
        $this->getJson('/api/v1/catalog/tipos-material')->assertOk();
        $this->getJson('/api/v1/catalog/categorias')->assertOk();
        $this->getJson("/api/v1/militares/{$this->c['efetivo']->id}/foto")->assertNotFound(); // autorizado; só não há foto
        $this->getJson("/api/v1/militares/{$this->c['efetivoOutro']->id}/foto")->assertForbidden();
        $e = $this->entrega($ids)->assertCreated()->json('id');
        $this->getJson("/api/v1/movements/$e")->assertOk(); // detalhe "Entrega #N" continua
        // Devolução só pelo próprio efetivo (regra 2026-11-18): outro efetivo da base é recusado; o efetivo da entrega é gravado.
        $outro = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id]);
        $itens = ['itens' => [['item_individual_id' => $ids['colete'], 'estado_conservacao' => 'INSERVIVEL'], ['item_individual_id' => $ids['arma']], ['lote_id' => $ids['lote'], 'quantidade' => 30]]];
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e, 'devolvido_por_militar_id' => $outro->id] + $itens)->assertUnprocessable()->assertJsonPath('error.code', 'DEVOLUCAO_SO_PELO_EFETIVO');
        $d = $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e] + $itens)->assertCreated()->json('id');
        $this->assertSame($this->c['efetivo']->id, Movimentacao::find($d)->devolvido_por_militar_id);
        $this->mov(['tipo' => 'BAIXA', 'local_origem_id' => $this->c['localAlfa1']->id, 'documento_referencia' => 'TERMO-1', 'justificativa' => 'Inservível na devolução',
            'itens' => [['item_individual_id' => $ids['colete']]]])->assertCreated();
        $this->assertSame('BAIXADO', ItemIndividual::find($ids['colete'])->situacao);
        // Acautelamento: pedido pelo armeiro com efetivo achado na busca do balcão.
        $this->postJson('/api/v1/acautelamentos/requests', ['militar_id' => $this->c['efetivo']->id, 'item_individual_id' => $ids['arma'], 'justificativa' => 'Serviço ordinário'])->assertCreated();
    }

    // ---------- 6. Documento e motivo em listas validadas no servidor ----------

    public function test_entrega_valida_documento_e_motivo_da_lista(): void
    {
        $ids = $this->estoque();
        $this->como($this->c['armeiro']);
        $op = $this->getJson('/api/v1/stock/delivery-options')->assertOk()->assertJsonPath('local_origem_padrao_id', $this->c['localAlfa1']->id);
        $this->assertContains('OUTRO', collect($op->json('motivos'))->pluck('codigo')->all());
        $this->assertContains('SEM_DOCUMENTO', collect($op->json('documentos'))->pluck('codigo')->all());

        $base = ['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa1']->id, 'militar_id' => $this->c['efetivo']->id, 'itens' => [['item_individual_id' => $ids['colete']]]];
        $this->mov($base + ['justificativa' => 'Texto livre'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['motivo_codigo']]]);
        $this->mov($base + ['motivo_codigo' => 'INVENTADO'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['motivo_codigo']]]);
        $this->mov($base + ['motivo_codigo' => 'OUTRO'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['justificativa']]]);
        $this->mov($base + ['motivo_codigo' => 'PATRULHA', 'documento_codigo' => 'BILHETE'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['documento_codigo']]]);
        $this->mov($base + ['motivo_codigo' => 'PATRULHA', 'documento_referencia' => 'livre'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['documento_referencia']]]);
        $e = $this->mov($base + ['motivo_codigo' => 'OUTRO', 'justificativa' => 'Apoio à Defesa Civil', 'documento_codigo' => 'OFICIO', 'documento_numero' => '12/2026'])->assertCreated()->json('id');
        $m = Movimentacao::find($e);
        $this->assertSame(['Ofício/Memorando nº 12/2026', 'Outro — Apoio à Defesa Civil'], [$m->documento_referencia, $m->justificativa]);
        $this->assertSame('FORA_DO_ESTOQUE', ItemIndividual::find($ids['colete'])->situacao); // sem previsão = consumo
    }

    public function test_local_padrao_pode_vir_da_configuracao(): void
    {
        $reserva2 = LocalEstoque::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'tipo' => 'RESERVA']);
        config(['sigarb.entrega.local_padrao_codigo' => $reserva2->codigo]);
        $this->como($this->c['armeiro'])->getJson('/api/v1/stock/delivery-options')->assertJsonPath('local_origem_padrao_id', $reserva2->id);
        // Depósito configurado como padrão é ignorado para o armeiro (depósito só para a gestão, 2026-11-18).
        config(['sigarb.entrega.local_padrao_codigo' => $this->c['depositoAlfa1']->codigo]);
        $this->getJson('/api/v1/stock/delivery-options')->assertJsonPath('local_origem_padrao_id', $this->c['localAlfa1']->id);
        // Local configurado fora do escopo é ignorado.
        config(['sigarb.entrega.local_padrao_codigo' => $this->c['localAlfa2']->codigo]);
        $this->getJson('/api/v1/stock/delivery-options')->assertJsonPath('local_origem_padrao_id', $this->c['localAlfa1']->id);
    }

    // ---------- 5. Relatório de entregas e devoluções ----------

    public function test_relatorio_agrega_armas_municao_e_demais_por_periodo_e_exporta(): void
    {
        $ids = $this->estoque();
        $this->como($this->c['armeiro']);
        $e = $this->entrega($ids)->assertCreated()->json('id');
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e, 'itens' => [['item_individual_id' => $ids['arma']], ['lote_id' => $ids['lote'], 'quantidade' => 12]]])->assertCreated();
        // Outra base não entra.
        $this->como($this->c['gestorAlfa'])->mov(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa2']->id, 'militar_id' => $this->c['efetivoOutro']->id,
            'motivo_codigo' => 'EVENTO', 'previsao_retorno' => now()->addDay()->toDateString(), 'itens' => [['item_individual_id' => $ids['outra']]]])->assertCreated();

        $this->como($this->c['armeiro']);
        $hoje = now('America/Maceio')->toDateString();
        $r = $this->getJson('/api/v1/reports/deliveries-returns?agrupar=dia')->assertOk()->assertJsonPath('ate', $hoje)->assertJsonCount(14, 'series');
        $this->assertSame(['armas' => ['entregues' => 1, 'devolvidas' => 1], 'municao' => ['entregues' => 30, 'devolvidas' => 12], 'demais' => ['entregues' => 1, 'devolvidas' => 0]], $r->json('totais'));
        $ultimo = collect($r->json('series'))->last();
        $this->assertSame([1, 1, 30, 12], [$ultimo['armas']['entregues'], $ultimo['armas']['devolvidas'], $ultimo['municao']['entregues'], $ultimo['municao']['devolvidas']]);
        $this->getJson('/api/v1/reports/deliveries-returns?agrupar=semana')->assertOk()->assertJsonPath('totais.armas.entregues', 1);
        $mes = $this->getJson('/api/v1/reports/deliveries-returns?agrupar=mes&de='.now()->subMonths(2)->startOfMonth()->toDateString()."&ate=$hoje")->assertOk();
        $this->assertCount(3, $mes->json('series'));
        $this->getJson('/api/v1/reports/deliveries-returns?agrupar=ano')->assertUnprocessable();
        $this->getJson('/api/v1/reports/deliveries-returns?de=2020-01-01&ate=2026-01-01')->assertUnprocessable();

        $csv = $this->get('/api/v1/reports/deliveries-returns/export?agrupar=mes')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Armas entregues";"Armas devolvidas";"Munição entregues"', $csv);
        $this->assertStringContainsString('Total;', $csv);
        $this->get('/api/v1/reports/deliveries-returns/export?formato=xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'relatorio.exportado', 'usuario_id' => $this->c['armeiro']->id]);

        $this->como($this->c['operadorAlfa1'])->getJson('/api/v1/reports/deliveries-returns')->assertForbidden();
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/reports/deliveries-returns')->assertOk()->assertJsonPath('totais.demais.entregues', 2);
    }
}
