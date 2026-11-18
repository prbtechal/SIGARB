<?php

namespace Tests\Feature;

use App\Models\AtribuicaoPapel;
use App\Models\Categoria;
use App\Models\ItemIndividual;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Notificacao;
use App\Models\Papel;
use App\Models\PostoGraduacao;
use App\Models\Usuario;
use App\Support\Busca;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Problemas relatados pelo responsável (2026-11-18):
 * 1. lote (munição) entregue sem "situação" no detalhe e nas telas do efetivo; 2. sigla CIV no lugar da categoria (RR, PM, BM, VIG, R1)
 * e avisos à T.I.; 3. busca da Entrega/Devolução de material não achava pelo nome do material; 5. só o próprio efetivo devolve.
 */
class ArmeiroLoteBuscaPostoTest extends TestCase
{
    use RefreshDatabase;

    private int $pt;

    private int $colete;

    private int $lote;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->cenario();
        $this->c['armeiro'] = $this->usuarioCom('ARMEIRO', $this->c['alfa1']->id);
        $civ = PostoGraduacao::firstOrCreate(['sigla' => 'CIV'], ['nome' => 'Civil / sem posto', 'ordem' => 0])->id;
        $this->c['rr'] = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'RR', 'posto_graduacao_id' => $civ,
            'nome' => 'CICERO LIMA DA SILVA', 'nome_guerra' => 'CICERO LIMA', 'matricula' => 'MAT-1183']);
        $arm = Categoria::firstOrCreate(['codigo' => 'ARM-T'], ['nome' => 'Armas teste', 'natureza' => 'ARMAMENTO']);
        $mun = Categoria::firstOrCreate(['codigo' => 'MUN-T'], ['nome' => 'Munição teste', 'natureza' => 'MUNICAO']);
        $pt = Material::factory()->create(['categoria_id' => $arm->id, 'nome' => 'PT 100 Cal. .40 Taurus', 'codigo' => 'ARM-PT100']);
        $col = Material::factory()->create(['nome' => 'Colete balístico - tamanho P', 'codigo' => 'COL-P']);
        $mu = Material::factory()->lote()->create(['categoria_id' => $mun->id, 'nome' => 'Munição .40 S&W', 'codigo' => 'MUN-40']);
        $this->como($this->c['gestorAlfa'])->mov(['tipo' => 'ENTRADA', 'local_destino_id' => $this->c['localAlfa1']->id, 'origem_descricao' => 'Origem', 'documento_referencia' => 'DOC-1',
            'itens' => [['material_id' => $pt->id, 'numero_serie' => 'SER-PT-1'], ['material_id' => $col->id, 'numero_serie' => 'SER-COL-1'],
                ['material_id' => $mu->id, 'lote_codigo' => 'INV2026', 'quantidade' => 50]]])->assertCreated();
        $this->pt = ItemIndividual::where('numero_serie', 'SER-PT-1')->value('id');
        $this->colete = ItemIndividual::where('numero_serie', 'SER-COL-1')->value('id');
        $this->lote = (int) DB::table('lotes')->where('codigo', 'INV2026')->value('id');
    }

    private function mov(array $corpo): TestResponse
    {
        return $this->withHeaders($this->chave())->postJson('/api/v1/movements', $corpo);
    }

    /** Entrega #1183 do relato: pistola + colete + 10 munições do lote INV2026. */
    private function entrega(bool $consumo = false): int
    {
        $this->como($this->c['armeiro']);

        return $this->mov(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa1']->id, 'militar_id' => $this->c['rr']->id, 'motivo_codigo' => 'PATRULHA',
            'itens' => $consumo ? [['lote_id' => $this->lote, 'quantidade' => 5]]
                : [['item_individual_id' => $this->pt], ['item_individual_id' => $this->colete], ['lote_id' => $this->lote, 'quantidade' => 10]]]
            + ($consumo ? [] : ['previsao_retorno' => now()->addDay()->toDateString()]))->assertCreated()->json('id');
    }

    private function loteDe(TestResponse $r): array
    {
        return collect($r->json('itens'))->firstWhere('tipo', 'LOTE');
    }

    // ---------- 1. Lote com situação equivalente em todas as telas ----------

    public function test_lote_entregue_tem_situacao_no_detalhe_nas_listas_e_no_portal(): void
    {
        $e = $this->entrega();
        $l = $this->loteDe($this->getJson("/api/v1/movements/$e")->assertOk());
        $this->assertSame(['situacao' => 'ENTREGUE', 'entregue' => 10, 'devolvido' => 0, 'pendente' => 10], $l['situacao_lote']);

        // Lista agrupada e busca da devolução: lote aparece como ENTREGUE (inclusive no filtro por situação) junto com os itens.
        $itens = collect($this->getJson('/api/v1/stock/linked')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_itens', 3)->json('data.0.itens'));
        $this->assertSame(['ENTREGUE', 10, 10, 0], [$itens->firstWhere('lote_id', $this->lote)['situacao'], $itens->firstWhere('lote_id', $this->lote)['quantidade'],
            $itens->firstWhere('lote_id', $this->lote)['quantidade_entregue'], $itens->firstWhere('lote_id', $this->lote)['quantidade_devolvida']]);
        $this->getJson('/api/v1/stock/linked?situacao=ENTREGUE')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/stock/linked?militar_id='.$this->c['rr']->id)->assertJsonPath('data.0.total_itens', 3);

        // Devolução parcial de 4 munições: "Parcial: 4 de 10 devolvidos", 6 pendentes em todas as telas.
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e, 'itens' => [['lote_id' => $this->lote, 'quantidade' => 4]]])->assertCreated();
        $det = $this->getJson("/api/v1/movements/$e");
        $this->assertSame(['situacao' => 'DEVOLVIDO_PARCIAL', 'entregue' => 10, 'devolvido' => 4, 'pendente' => 6], $this->loteDe($det)['situacao_lote']);
        $this->assertSame(['quantidade' => 6, 'entregue' => 10, 'devolvido' => 4], collect($det->json('pendente_devolucao.lotes.0'))->only(['quantidade', 'entregue', 'devolvido'])->all());
        $l = collect($this->getJson('/api/v1/stock/linked')->json('data.0.itens'))->firstWhere('lote_id', $this->lote);
        $this->assertSame([6, 10, 4], [$l['quantidade'], $l['quantidade_entregue'], $l['quantidade_devolvida']]);

        // Portal "Material comigo".
        $conta = Usuario::factory()->create(['militar_id' => $this->c['rr']->id]);
        AtribuicaoPapel::create(['usuario_id' => $conta->id, 'papel_id' => Papel::where('codigo', 'EFETIVO')->value('id'), 'unidade_id' => $this->c['alfa1']->id]);
        $p = collect($this->como($conta)->getJson('/api/v1/portal/material')->assertOk()->json('data'))->firstWhere('lote_codigo', 'INV2026');
        $this->assertSame(['ENTREGUE', 6, 10, 4], [$p['situacao'], $p['quantidade'], $p['quantidade_entregue'], $p['quantidade_devolvida']]);

        // Devolução do restante (total): lote DEVOLVIDO no detalhe da entrega e no da devolução.
        $d = $this->como($this->c['armeiro'])->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e])->assertCreated()->json('id');
        $this->assertSame('DEVOLVIDO', $this->loteDe($this->getJson("/api/v1/movements/$e"))['situacao_lote']['situacao']);
        $sd = $this->loteDe($this->getJson("/api/v1/movements/$d"))['situacao_lote'];
        $this->assertSame(['DEVOLVIDO', 10, 0], [$sd['situacao'], $sd['devolvido'], $sd['pendente']]);
        $this->getJson('/api/v1/stock/linked')->assertJsonPath('meta.total', 0);
    }

    public function test_lote_entregue_sem_retorno_aparece_como_consumido(): void
    {
        $e = $this->entrega(consumo: true);
        $this->assertSame('CONSUMIDO', $this->loteDe($this->getJson("/api/v1/movements/$e"))['situacao_lote']['situacao']);
        $this->getJson('/api/v1/stock/linked')->assertJsonPath('meta.total', 0);
    }

    // ---------- 3. Busca por nome/código do material, efetivo, série, lote e entrega ----------

    public function test_busca_da_entrega_e_devolucao_acha_pelo_nome_do_material(): void
    {
        $e = $this->entrega();
        foreach (['PT', 'pt', 'colete', 'COLETE BALISTICO', 'balístico', 'munições', 'municao', 'Taurus', 'PT 100', 'ARM-PT100', 'INV2026', 'SER-COL',
            'cicero', 'Cícero Lima', 'MAT-1183', "#$e"] as $q) {
            $this->getJson('/api/v1/stock/linked?q='.urlencode($q))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_itens', 3);
        }
        foreach (['fuzil', 'PT 777Q', 'colete XYZ9'] as $q) {
            $this->getJson('/api/v1/stock/linked?q='.urlencode($q))->assertJsonPath('meta.total', 0);
        }
    }

    public function test_seletor_do_formulario_de_entrega_busca_por_material_e_pagina_no_servidor(): void
    {
        $this->como($this->c['armeiro']);
        $local = $this->c['localAlfa1']->id;
        $series = fn (string $q) => collect($this->getJson("/api/v1/stock/individual-items?local_id=$local&situacao=DISPONIVEL&q=".urlencode($q))->assertOk()->json('data'))->pluck('numero_serie')->all();
        $this->assertSame(['SER-PT-1'], $series('PT'));
        $this->assertSame(['SER-COL-1'], $series('colete'));
        $this->assertSame(['SER-COL-1'], $series('SER-COL'));
        $lotes = fn (string $q) => $this->getJson("/api/v1/stock/balances?local_id=$local&lotes_disponiveis=1&per_page=10&q=".urlencode($q))->assertOk();
        $lotes('munições')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.lote.codigo', 'INV2026');
        $lotes('')->assertJsonPath('meta.total', 1); // só lotes, sem as linhas agregadas de itens individuais
        $lotes('colete')->assertJsonPath('meta.total', 0);
        // Paginação no servidor: per_page=1 com dois itens disponíveis.
        $this->getJson("/api/v1/stock/individual-items?local_id=$local&situacao=DISPONIVEL&per_page=1")->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
    }

    public function test_radical_da_busca(): void
    {
        $this->assertSame(['muniç'], Busca::termos('munições'));
        $this->assertSame(['muniç'], Busca::termos('Munição'));
        $this->assertSame(['colete', 'pt'], Busca::termos('  Coletes   PT '));
        $this->assertSame(['10%_'], Busca::termos('10%_'));
        $this->assertSame('%10\\%\\_%', Busca::like('10%_'));
    }

    // ---------- 2. Sigla de exibição e avisos para a T.I. ----------

    public function test_sigla_de_exibicao_troca_civ_pela_categoria(): void
    {
        foreach ([['CIV', 'RR', 'RR'], ['CIV', 'PM_ATIVA', 'PM'], ['CIV', 'BM_ATIVA', 'BM'], ['CIV', 'VIGILANTE', 'VIG'], ['CIV', 'R1', 'R1'], ['CIV', null, null], ['SD', 'PM_ATIVA', 'SD'], ['3SGT', 'RR', '3SGT'], [null, null, null]] as [$posto, $cat, $esperado]) {
            $this->assertSame($esperado, Militar::siglaExibicao($posto, $cat), "$posto/$cat");
        }
        $this->assertSame('RR CICERO LIMA', Militar::rotulo('CIV', 'RR', 'CICERO LIMA'));
        $this->assertSame('JOAO', Militar::rotulo('CIV', null, 'JOAO'));

        $e = $this->entrega();
        $this->getJson('/api/v1/stock/linked')->assertJsonPath('data.0.militar.posto', 'RR');
        $this->getJson('/api/v1/stock/recipients?q=CICERO')->assertJsonPath('data.0.sigla_exibicao', 'RR')->assertJsonPath('data.0.rotulo_curto', 'RR CICERO LIMA')
            ->assertJsonPath('data.0.posto_graduacao.sigla', 'CIV');
        $this->como($this->c['gestorAlfa']);
        $this->assertSame('RR', collect($this->getJson('/api/v1/movements?per_page=50')->json('data'))->firstWhere('id', $e)['militar']['posto']);
        $this->getJson('/api/v1/militares?q=CICERO')->assertJsonPath('data.0.rotulo_curto', 'RR CICERO LIMA')->assertJsonPath('data.0.posto_a_atualizar', true);
        $csv = $this->get('/api/v1/reports/movimentacoes')->streamedContent();
        $this->assertStringContainsString('RR CICERO LIMA', $csv);
    }

    public function test_alerta_e_filtro_de_posto_a_atualizar_para_a_ti(): void
    {
        $vig = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'VIGILANTE',
            'posto_graduacao_id' => PostoGraduacao::where('sigla', 'CIV')->value('id')]);
        Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'PM_ATIVA']); // SD: ok
        $this->como($this->c['admin']);
        $a = collect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->firstWhere('tipo', 'POSTO_A_ATUALIZAR');
        $this->assertSame([1, '/militares?posto_a_atualizar=1'], [$a['quantidade'], $a['link']]);
        $this->assertSame([$this->c['rr']->id], collect($this->getJson('/api/v1/militares?posto_a_atualizar=1')->assertOk()->json('data'))->pluck('id')->all());
        $this->assertNotContains($vig->id, collect($this->getJson('/api/v1/militares?posto_a_atualizar=1')->json('data'))->pluck('id')->all());
        // Armeiro (sem gestão de efetivos/usuários) não recebe o alerta.
        $this->assertNull(collect($this->como($this->c['armeiro'])->getJson('/api/v1/alerts')->json('data'))->firstWhere('tipo', 'POSTO_A_ATUALIZAR'));
    }

    public function test_mudanca_de_categoria_para_rr_pm_bm_com_posto_civ_notifica_sem_duplicar(): void
    {
        $civ = PostoGraduacao::where('sigla', 'CIV')->value('id');
        $m = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'VIGILANTE', 'posto_graduacao_id' => $civ, 'nome' => 'JOSE ALVES']);
        $this->como($this->c['admin']);
        $this->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'RR', 'motivo' => 'Conferido na ficha'])->assertOk();
        $n = Notificacao::where('tipo', 'POSTO_A_ATUALIZAR')->where('militar_id', $m->id);
        $this->assertTrue((clone $n)->where('usuario_id', $this->c['admin']->id)->exists());
        $this->assertTrue((clone $n)->where('usuario_id', $this->c['gestorAlfa']->id)->exists()); // militares.gerir na unidade-mãe
        $this->assertFalse((clone $n)->where('usuario_id', $this->c['gestorBravo']->id)->exists()); // fora do escopo
        $this->assertFalse((clone $n)->where('usuario_id', $this->c['armeiro']->id)->exists());
        $total = (clone $n)->count();
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'militar.aviso_posto', 'recurso_id' => $m->id]);
        $lista = $this->getJson('/api/v1/notifications')->assertOk()->json('data.0');
        $this->assertSame(['POSTO_A_ATUALIZAR', "/militares/{$m->id}"], [$lista['tipo'], $lista['link']]);
        $this->assertStringContainsString('Atualize o posto/graduação de JOSE ALVES (agora RR (PM/BM))', $lista['texto']);

        // Outra troca com aviso ainda não lido: não duplica. Lido o aviso do admin, a próxima troca avisa só ele de novo.
        $this->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'PM_ATIVA', 'motivo' => 'Correção da ficha'])->assertOk();
        $this->assertSame($total, (clone $n)->count());
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'BM_ATIVA', 'motivo' => 'Correção da ficha'])->assertOk();
        $this->assertSame($total + 1, (clone $n)->count());
        // Para categoria sem posto (vigilante) ou com posto real, nada.
        $this->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'VIGILANTE', 'motivo' => 'Correção da ficha'])->assertOk();
        $m->update(['posto_graduacao_id' => PostoGraduacao::where('sigla', 'SD')->value('id') ?? PostoGraduacao::create(['sigla' => 'SD', 'nome' => 'Soldado', 'ordem' => 1])->id]);
        $this->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'RR', 'motivo' => 'Correção da ficha'])->assertOk();
        $this->assertSame($total + 1, (clone $n)->count());
    }
}
