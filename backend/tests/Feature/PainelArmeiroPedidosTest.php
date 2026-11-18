<?php

namespace Tests\Feature;

use App\Models\AtribuicaoPapel;
use App\Models\Categoria;
use App\Models\ItemIndividual;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Papel;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Pedidos do responsável (2026-11-18): Início com cards por tipo de material (GET /dashboard/materials), filtro `grupo` nas listas,
 * contadores das abas de Pedidos de material e Devolução já aberta com os efetivos que têm material pendente (GET /stock/linked/recipients).
 * Cenário: armeiro da base ALFA-1; BRAVO é outra base (nada dela pode aparecer).
 */
class PainelArmeiroPedidosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->cenario();
        $this->c['armeiro'] = $this->usuarioCom('ARMEIRO', $this->c['alfa1']->id);
        $this->c['ef'] = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'PM_ATIVA', 'nome_guerra' => 'PRIMEIRO']);
        $this->c['ef2'] = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'RR', 'nome_guerra' => 'SEGUNDO']);
        // Catálogo oficial: categoria por código + tipo/perfil (firstOrCreate: a migração do catálogo já cria a maioria).
        $cat = fn (string $cod, string $nome, string $nat) => Categoria::firstOrCreate(['codigo' => $cod], ['nome' => $nome, 'natureza' => $nat]);
        $tipo = fn (Categoria $c, string $nome, string $perfil) => TipoMaterial::firstOrCreate(['categoria_id' => $c->id, 'nome' => $nome], ['perfil' => $perfil, 'ativo' => true]);
        $armas = $cat('009', 'Armas', 'ARMAMENTO');
        $mun = $cat('011', 'Munição', 'MUNICAO');
        $epi = $cat('001', 'EPI', 'EQUIPAMENTO');
        $mat = fn (Categoria $c, TipoMaterial $t, string $nome, bool $lote = false) => ($lote ? Material::factory()->lote() : Material::factory())
            ->create(['categoria_id' => $c->id, 'tipo_id' => $t->id, 'nome' => $nome]);
        $this->c['arma'] = $mat($armas, $tipo($armas, 'Arma de fogo (letal)', 'ARMA_FOGO'), 'Pistola fictícia');
        $this->c['taser'] = $mat($armas, $tipo($armas, 'Taser ou equipamento semelhante (não letal)', 'BAIXA_LETALIDADE'), 'Taser fictício');
        $this->c['municao'] = $mat($mun, $tipo($mun, 'Munição letal', 'GERAL'), 'Munição fictícia', true);
        $this->c['cartucho'] = $mat($mun, $tipo($mun, 'Cartucho de Taser (não letal)', 'ACESSORIO'), 'Cartucho fictício', true);
        $this->c['colete'] = $mat($epi, $tipo($epi, 'Colete balístico', 'GERAL'), 'Colete fictício');
    }

    private function mov(array $corpo): TestResponse
    {
        return $this->withHeaders($this->chave())->postJson('/api/v1/movements', $corpo);
    }

    /** Base ALFA-1: 2 pistolas, 1 taser, 1 colete (vencido), 100 munições, 20 cartuchos; BRAVO: 1 pistola e 50 munições. */
    private function estoque(): array
    {
        $entrada = fn ($local, array $itens) => $this->mov(['tipo' => 'ENTRADA', 'local_destino_id' => $local->id, 'origem_descricao' => 'Origem fictícia',
            'documento_referencia' => 'DOC-P', 'itens' => $itens])->assertCreated();
        $this->como($this->c['gestorAlfa']);
        $entrada($this->c['localAlfa1'], [
            ['material_id' => $this->c['arma']->id, 'numero_serie' => 'PST-1'], ['material_id' => $this->c['arma']->id, 'numero_serie' => 'PST-2'],
            ['material_id' => $this->c['taser']->id, 'numero_serie' => 'TSR-1'], ['material_id' => $this->c['colete']->id, 'numero_serie' => 'COL-1'],
            ['material_id' => $this->c['municao']->id, 'lote_codigo' => 'MUN-1', 'quantidade' => 100],
            ['material_id' => $this->c['cartucho']->id, 'lote_codigo' => 'CRT-1', 'quantidade' => 20]]);
        $this->como($this->c['gestorBravo']);
        $entrada($this->c['localBravo'], [['material_id' => $this->c['arma']->id, 'numero_serie' => 'PST-B'],
            ['material_id' => $this->c['municao']->id, 'lote_codigo' => 'MUN-B', 'quantidade' => 50]]);
        ItemIndividual::where('numero_serie', 'COL-1')->update(['validade' => now()->subDays(3)->toDateString()]);

        return ['pst1' => ItemIndividual::where('numero_serie', 'PST-1')->value('id'), 'lote' => (int) DB::table('lotes')->where('codigo', 'MUN-1')->value('id'),
            'tsr' => ItemIndividual::where('numero_serie', 'TSR-1')->value('id')];
    }

    private function entregar(Militar $m, array $itens, int $dias = 2): void
    {
        $this->como($this->c['armeiro']);
        $this->mov(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa1']->id, 'militar_id' => $m->id,
            'previsao_retorno' => now()->addDays($dias)->toDateString(), 'motivo_codigo' => 'PATRULHA', 'documento_codigo' => 'ESCALA', 'itens' => $itens])->assertCreated();
    }

    private function grupo(TestResponse $r, string $chave): array
    {
        return collect($r->json('grupos'))->firstWhere('chave', $chave);
    }

    public function test_painel_conta_por_tipo_de_material_so_na_base_e_sem_n_mais_1(): void
    {
        $ids = $this->estoque();
        $this->entregar($this->c['ef'], [['item_individual_id' => $ids['pst1']], ['lote_id' => $ids['lote'], 'quantidade' => 30]]);

        $this->como($this->c['armeiro']);
        DB::enableQueryLog();
        $r = $this->getJson('/api/v1/dashboard/materials')->assertOk();
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $arma = $this->grupo($r, 'ARMA_FOGO');
        $this->assertSame([1, 1, 1], [$arma['disponiveis'], $arma['com_efetivos'], $arma['efetivos']], 'BRAVO fica fora; 1 pistola entregue');
        $mun = $this->grupo($r, 'MUNICAO');
        $this->assertSame([70, 30, 100, 30, 100, 30], [$mun['disponiveis'], $mun['com_efetivos'], $mun['entradas_hoje'], $mun['saidas_hoje'], $mun['entradas_7_dias'], $mun['saidas_7_dias']]);
        $this->assertSame(20, $this->grupo($r, 'CARTUCHO_TASER')['disponiveis'], 'cartucho de Taser tem grupo próprio, fora de Munição');
        $this->assertSame(1, $this->grupo($r, 'TASER')['disponiveis']);
        $colete = $this->grupo($r, 'COLETE');
        $this->assertSame([1, 1, 0, true], [$colete['disponiveis'], $colete['vencidos'], $colete['vencendo'], $colete['tem_validade']]);
        $this->assertSame(['ARMA_FOGO', 'MUNICAO', 'COLETE', 'TASER', 'CARTUCHO_TASER', 'RADIO', 'ALGEMA', 'OUTROS'], collect($r->json('grupos'))->pluck('chave')->all());
        $this->assertSame([0, 0, 0], [$r->json('operacao.pedidos_pendentes'), $r->json('operacao.pedidos_aprovados'), $r->json('operacao.entregas_atrasadas')]);
        $this->assertLessThanOrEqual(15, $consultas, 'agregado por bloco, sem consulta por grupo/material');

        // Gestor da raiz ALFA não alcança BRAVO; o gestor de BRAVO só vê a sua pistola.
        $this->como($this->c['gestorAlfa']);
        $this->assertSame(1, $this->grupo($this->getJson('/api/v1/dashboard/materials')->assertOk(), 'ARMA_FOGO')['disponiveis']);
        $this->como($this->c['gestorBravo']);
        $this->assertSame([1, 0], [$this->grupo($r2 = $this->getJson('/api/v1/dashboard/materials')->assertOk(), 'ARMA_FOGO')['disponiveis'], $this->grupo($r2, 'ARMA_FOGO')['com_efetivos']]);

        // Entrega com devolução atrasada aparece na operação do dia.
        $this->travel(4)->days();
        $this->como($this->c['armeiro']);
        $this->assertSame(1, $this->getJson('/api/v1/dashboard/materials')->assertOk()->json('operacao.entregas_atrasadas'));
    }

    public function test_painel_exige_estoque_ver_e_link_do_card_filtra_a_lista_pelo_grupo(): void
    {
        $ids = $this->estoque();
        $this->entregar($this->c['ef'], [['item_individual_id' => $ids['pst1']]]);
        $ef = Usuario::factory()->create(['militar_id' => $this->c['ef']->id]);
        AtribuicaoPapel::create(['usuario_id' => $ef->id, 'papel_id' => Papel::where('codigo', 'EFETIVO')->value('id'), 'unidade_id' => $this->c['alfa1']->id]);
        $this->como($ef);
        $this->getJson('/api/v1/dashboard/materials')->assertForbidden();
        $this->getJson('/api/v1/stock/linked/recipients')->assertForbidden();

        $this->como($this->c['armeiro']);
        $saldos = $this->getJson('/api/v1/stock/balances?grupo=MUNICAO')->assertOk();
        $this->assertSame(['Munição fictícia'], collect($saldos->json('data'))->pluck('material.nome')->unique()->values()->all());
        $this->assertSame(100, collect($saldos->json('data'))->sum('disponivel'));
        $this->assertSame(['Cartucho fictício'], collect($this->getJson('/api/v1/stock/balances?grupo=CARTUCHO_TASER')->json('data'))->pluck('material.nome')->all());
        $this->assertCount(1, $this->getJson('/api/v1/stock/linked?grupo=ARMA_FOGO')->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/stock/linked?grupo=MUNICAO')->assertOk()->json('data'));
        $this->getJson('/api/v1/stock/balances?grupo=INVALIDO')->assertStatus(422);
    }

    public function test_devolucao_lista_efetivos_com_pendencia_atrasados_primeiro_e_busca_filtra(): void
    {
        $ids = $this->estoque();
        $this->entregar($this->c['ef2'], [['lote_id' => $ids['lote'], 'quantidade' => 10]], 10);  // previsão longe
        $this->entregar($this->c['ef'], [['item_individual_id' => $ids['pst1']], ['lote_id' => $ids['lote'], 'quantidade' => 5]], 1);
        $this->entregar($this->c['ef'], [['item_individual_id' => $ids['tsr']]], 3);

        $this->como($this->c['armeiro']);
        $r = $this->getJson('/api/v1/stock/linked/recipients')->assertOk();
        $this->assertSame(['PRIMEIRO', 'SEGUNDO'], collect($r->json('data'))->pluck('militar.nome_guerra')->all(), 'previsão mais próxima primeiro');
        $p = $r->json('data.0');
        $this->assertSame([2, 2, 1, 5, false], [$p['entregas'], $p['itens'], $p['lotes'], $p['quantidade_lotes'], $p['atrasado']]);
        $this->assertSame(now()->addDay()->toDateString(), $p['proxima_previsao']);
        $this->assertSame(2, $r->json('meta.total'));

        // Depois de 5 dias, só a entrega do SEGUNDO (10 dias) não está atrasada: PRIMEIRO segue no topo, agora com o selo.
        $this->travel(5)->days();
        $r = $this->getJson('/api/v1/stock/linked/recipients')->assertOk();
        $this->assertSame([true, 2], [$r->json('data.0.atrasado'), $r->json('data.0.atrasadas')]);
        $this->assertFalse($r->json('data.1.atrasado'));

        $this->assertSame(['SEGUNDO'], collect($this->getJson('/api/v1/stock/linked/recipients?q=segundo')->json('data'))->pluck('militar.nome_guerra')->all());
        // Outra base não vê ninguém.
        $this->como($this->usuarioCom('ARMEIRO', $this->c['bravo']->id));
        $this->assertSame(0, $this->getJson('/api/v1/stock/linked/recipients')->assertOk()->json('meta.total'));
    }

    public function test_contadores_das_abas_de_pedidos_respeitam_busca_e_escopo(): void
    {
        $this->estoque();
        $contas = [];
        $pedir = function (Militar $m) use (&$contas) {
            if (! isset($contas[$m->id])) {
                $contas[$m->id] = Usuario::factory()->create(['militar_id' => $m->id]);
                AtribuicaoPapel::create(['usuario_id' => $contas[$m->id]->id, 'papel_id' => Papel::where('codigo', 'EFETIVO')->value('id'), 'unidade_id' => $m->unidade_id]);
            }
            $this->como($contas[$m->id]);

            return $this->postJson('/api/v1/portal/requests', ['itens' => [['material_id' => $this->c['colete']->id, 'quantidade' => 1]]])->assertCreated()->json('codigo');
        };
        $pedir($this->c['ef']);
        $cod = $pedir($this->c['ef2']);
        $pedir($this->c['ef2']);
        $this->como($this->c['armeiro']);
        $atual = $this->getJson("/api/v1/material-requests/$cod")->assertOk();
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'APROVAR', 'versao' => $atual->json('versao')])->assertOk();

        $s = $this->getJson('/api/v1/material-requests/summary')->assertOk();
        $this->assertSame([2, 1], [$s->json('pendentes'), $s->json('aprovados')]);
        $this->assertEquals(['PENDENTE' => 2, 'APROVADO' => 1, 'ENTREGUE' => 0, 'RECUSADO' => 0, 'CANCELADO' => 0], $s->json('por_status'));
        $this->assertNotNull($s->json('pendente_mais_antigo'));
        $f = $this->getJson('/api/v1/material-requests/summary?q=segundo')->assertOk();
        $this->assertSame(['PENDENTE' => 1, 'APROVADO' => 1], array_intersect_key($f->json('por_status'), ['PENDENTE' => 0, 'APROVADO' => 0]));
        $this->assertSame([2, 1], [$f->json('pendentes'), $f->json('aprovados')], 'contadores do menu não seguem a busca');
        $lista = $this->getJson('/api/v1/material-requests?status=PENDENTE&sort=id')->assertOk();
        $this->assertSame($this->c['ef']->nome, $lista->json('data.0.efetivo.nome'), 'mais antigo primeiro; nome para a lista');
        $this->assertSame(2, $this->getJson('/api/v1/dashboard/materials')->assertOk()->json('operacao.pedidos_pendentes'));

        $this->como($this->usuarioCom('ARMEIRO', $this->c['bravo']->id));
        $this->assertSame(0, array_sum($this->getJson('/api/v1/material-requests/summary')->assertOk()->json('por_status')));
    }
}
