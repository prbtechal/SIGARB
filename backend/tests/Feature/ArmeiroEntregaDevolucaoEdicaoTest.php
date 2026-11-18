<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\ItemIndividual;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Movimentacao;
use App\Models\TipoMaterial;
use App\Support\Natureza;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Decisões do responsável (2026-11-16): entrega pela tela "Material com efetivo", devolução prática (estado por item, quem devolveu,
 * data/hora), baixa do que voltou com problema, edição de item/lote com motivo, ajuste de quantidade de lote e rótulo "EPI".
 */
class ArmeiroEntregaDevolucaoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->cenario();
        $this->c['armeiro'] = $this->usuarioCom('ARMEIRO', $this->c['alfa']->id);
        $this->c['efetivo'] = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'categoria_efetivo' => 'R1']);
    }

    private function mov(array $corpo, ?array $chave = null): TestResponse
    {
        return $this->withHeaders($chave ?? $this->chave())->postJson('/api/v1/movements', $corpo);
    }

    /** Payload que a tela "Registrar entrega a efetivo" envia. */
    private function entrega(array $itens, ?Militar $m = null, ?string $retorno = '+3 days'): TestResponse
    {
        return $this->mov(array_filter(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa']->id, 'militar_id' => ($m ?? $this->c['efetivo'])->id,
            'previsao_retorno' => $retorno ? now()->modify($retorno)->toDateString() : null, 'documento_codigo' => 'CAUTELA', 'documento_numero' => '77',
            'motivo_codigo' => 'PATRULHA', 'itens' => $itens]));
    }

    private function itens(string ...$series): array
    {
        foreach ($series as $s) {
            $this->entradaPelaGestao($this->entradaIndividual($this->c['localAlfa'], $s))->assertCreated();
        }

        return ItemIndividual::whereIn('numero_serie', $series)->orderBy('numero_serie')->pluck('id')->all();
    }

    private function lote(int $qtd = 50, string $codigo = 'L-001'): int
    {
        $this->entradaPelaGestao($this->entradaLote($this->c['localAlfa'], $qtd, $codigo))->assertCreated();

        return (int) DB::table('lotes')->where('codigo', $codigo)->value('id');
    }

    private function saldo(int $lote): int
    {
        return (int) DB::table('saldos_estoque')->where('lote_id', $lote)->where('local_estoque_id', $this->c['localAlfa']->id)->value('quantidade');
    }

    public function test_entrega_pela_tela_fica_pendente_e_aparece_em_material_com_efetivo(): void
    {
        $this->como($this->c['armeiro']);
        [$a, $b] = $this->itens('EPI-SER-1', 'EPI-SER-2');
        $lote = $this->lote();

        $e = $this->entrega([['item_individual_id' => $a], ['item_individual_id' => $b], ['lote_id' => $lote, 'quantidade' => 10]])->assertCreated()
            ->assertJsonPath('militar.id', $this->c['efetivo']->id)->json('id');
        $this->assertSame(['ENTREGUE', 'ENTREGUE'], ItemIndividual::whereIn('id', [$a, $b])->orderBy('id')->pluck('situacao')->all());
        $this->assertSame(40, $this->saldo($lote)); // o que está com o efetivo sai do saldo
        $this->assertSame(['Cautela nº 77', 'Serviço de patrulha (escala)'], [Movimentacao::find($e)->documento_referencia, Movimentacao::find($e)->justificativa]);

        // Decisão 2026-11-18: uma linha por entrega, com os 3 materiais dentro.
        $lista = $this->getJson('/api/v1/stock/linked')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_itens', 3);
        $linha = collect($lista->json('data.0.itens'))->firstWhere('lote_id', $lote);
        $this->assertSame([10, $e, false, true, 'EPI'], [$linha['quantidade'], $lista->json('data.0.entrega_id'), $lista->json('data.0.atrasado'), $lista->json('data.0.pode_receber'), $linha['material']['natureza_rotulo']]);
        // Filtro que casa um material traz a entrega inteira.
        $this->getJson('/api/v1/stock/linked?q=EPI-SER-2')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_itens', 3);
        $this->getJson('/api/v1/stock/linked?material_id='.$this->c['matLote']->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/stock/linked?q='.$this->c['efetivo']->nome_guerra)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/stock/linked?atrasados=1')->assertJsonPath('meta.total', 0);

        // Atrasado: previsão vencida destaca e filtra.
        $this->travel(5)->days();
        $this->getJson('/api/v1/stock/linked?atrasados=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.atrasado', true);
    }

    public function test_letal_bloqueado_para_r1_vigilante_e_sem_tipo_e_liberado_para_rr(): void
    {
        $this->como($this->c['armeiro']);
        $armas = Categoria::firstOrCreate(['codigo' => 'ARM-T'], ['nome' => 'Armas teste', 'natureza' => 'ARMAMENTO']);
        $this->c['matInd'] = Material::factory()->create(['categoria_id' => $armas->id]);
        [$arma] = $this->itens('PST-0001');

        foreach (['R1', 'VIGILANTE', null] as $cat) {
            $m = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'categoria_efetivo' => $cat]);
            $this->entrega([['item_individual_id' => $arma]], $m)->assertStatus(422)->assertJsonPath('error.code', 'EFETIVO_NAO_APTO_LETAL');
        }
        $this->assertSame('DISPONIVEL', ItemIndividual::find($arma)->situacao);
        $this->assertSame(0, Movimentacao::where('tipo', 'ENTREGA')->count());

        $rr = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'categoria_efetivo' => 'RR']);
        $this->entrega([['item_individual_id' => $arma]], $rr)->assertCreated();
        $this->assertSame('ENTREGUE', ItemIndividual::find($arma)->situacao);
    }

    public function test_devolucao_parcial_e_total_com_estado_por_item_quem_devolveu_e_data(): void
    {
        $this->como($this->c['armeiro']);
        [$a, $b] = $this->itens('DV-1', 'DV-2');
        $lote = $this->lote(30);
        $this->travelTo(now()->subHours(3));
        $e = $this->entrega([['item_individual_id' => $a], ['item_individual_id' => $b], ['lote_id' => $lote, 'quantidade' => 30]])->assertCreated()->json('id');
        $this->travelBack();

        // Retroativa sem justificativa, no futuro ou antes da entrega: recusadas.
        $base = ['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e, 'itens' => [['item_individual_id' => $a, 'estado_conservacao' => 'DANIFICADO']]];
        $this->mov($base + ['ocorrido_em' => now()->subHour()->toIso8601String()])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['justificativa']]]);
        $this->mov($base + ['ocorrido_em' => now()->addHour()->toIso8601String(), 'justificativa' => 'x'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['ocorrido_em']]]);
        $this->mov($base + ['ocorrido_em' => now()->subHours(5)->toIso8601String(), 'justificativa' => 'Antes da entrega'])->assertUnprocessable()
            ->assertJsonPath('error.fields.ocorrido_em.0', 'A devolução não pode ser anterior à entrega.');
        // Regra 2026-11-18: só o próprio efetivo devolve — terceiro (nome) ou outro efetivo são recusados com código próprio.
        $this->mov($base + ['devolvido_por_nome' => 'Terceiro', 'devolvido_por_militar_id' => $this->c['efetivo']->id])->assertUnprocessable();
        $this->mov($base + ['devolvido_por_nome' => 'José Terceiro'])->assertUnprocessable()->assertJsonPath('error.code', 'DEVOLUCAO_SO_PELO_EFETIVO');

        // Parcial: item A danificado, 10 unidades, registrada 1 h atrás com justificativa; grava o próprio efetivo.
        $hora = now()->subHour()->startOfSecond();
        $p = $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e, 'ocorrido_em' => $hora->toIso8601String(), 'justificativa' => 'Recebido no plantão anterior',
            'devolvido_por_militar_id' => $this->c['efetivo']->id, 'itens' => [['item_individual_id' => $a, 'estado_conservacao' => 'DANIFICADO'], ['lote_id' => $lote, 'quantidade' => 10]]])
            ->assertCreated()->assertJsonPath('devolvido_por.militar.id', $this->c['efetivo']->id)->assertJsonPath('devolvido_por.nome', null)->assertJsonPath('entrega_id', $e)->json('id');
        $mov = Movimentacao::find($p);
        $this->assertSame([$this->c['efetivo']->id, null, $hora->toDateTimeString()], [$mov->devolvido_por_militar_id, $mov->devolvido_por_nome, $mov->ocorrido_em->toDateTimeString()]);
        $this->assertDatabaseHas('movimentacao_itens', ['movimentacao_id' => $p, 'item_individual_id' => $a, 'estado_conservacao' => 'DANIFICADO', 'estado_anterior' => 'BOM']);
        $this->assertSame(['DISPONIVEL', 'DANIFICADO'], [ItemIndividual::find($a)->situacao, ItemIndividual::find($a)->estado_conservacao]);
        $ev = DB::table('eventos_auditoria')->where('acao', 'item.conservacao_alterada')->where('recurso_id', $a)->first();
        $this->assertSame(['BOM', 'DANIFICADO', $p], [json_decode($ev->dados, true)['antes'], json_decode($ev->dados, true)['depois'], json_decode($ev->dados, true)['movimentacao_id']]);
        $dev = json_decode(DB::table('eventos_auditoria')->where('acao', 'estoque.devolucao')->where('recurso_id', $p)->value('dados'), true);
        $this->assertSame([null, true], [$dev['devolvido_por_nome'] ?? null, $dev['retroativa']]);
        $this->assertSame(10, $this->saldo($lote));
        $pend = $this->getJson('/api/v1/stock/linked')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_itens', 2)->json('data.0.itens');
        $this->assertSame(20, collect($pend)->firstWhere('lote_id', $lote)['quantidade']); // parcial descontada
        $this->assertNull(collect($pend)->firstWhere('item_individual_id', $a));

        // Total sem lista: quem devolveu = o próprio efetivo (padrão); estado do item B = o atual (BOM).
        $t = $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e])->assertCreated()
            ->assertJsonPath('devolvido_por.militar.id', $this->c['efetivo']->id)->json('id');
        $this->assertSame($this->c['efetivo']->id, Movimentacao::find($t)->devolvido_por_militar_id);
        $this->assertDatabaseHas('movimentacao_itens', ['movimentacao_id' => $t, 'item_individual_id' => $b, 'estado_conservacao' => 'BOM', 'estado_anterior' => 'BOM']);
        $this->assertSame([30, 0], [$this->saldo($lote), $this->getJson('/api/v1/stock/linked')->json('meta.total')]);
    }

    public function test_devolucao_so_pelo_proprio_efetivo_e_baixa_do_item_inservivel(): void
    {
        $this->como($this->c['armeiro']);
        [$a, $b] = $this->itens('BX-1', 'BX-2');
        $e = $this->entrega([['item_individual_id' => $a], ['item_individual_id' => $b]])->assertCreated()->json('id');
        $baixa = fn (int $item) => $this->mov(['tipo' => 'BAIXA', 'local_origem_id' => $this->c['localAlfa']->id, 'documento_referencia' => 'TERMO-BX-1',
            'justificativa' => 'Devolvido inservível', 'itens' => [['item_individual_id' => $item]]]);
        $baixa($a)->assertStatus(409)->assertJsonPath('error.code', 'ITEM_INDISPONIVEL'); // ainda com o efetivo

        $colega = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);
        $itens = ['itens' => [['item_individual_id' => $a, 'estado_conservacao' => 'INSERVIVEL'], ['item_individual_id' => $b]]];
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e, 'devolvido_por_militar_id' => $colega->id] + $itens)->assertUnprocessable()
            ->assertJsonPath('error.code', 'DEVOLUCAO_SO_PELO_EFETIVO');
        $this->assertSame('ENTREGUE', ItemIndividual::find($a)->situacao); // nada mudou
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e] + $itens)->assertCreated()
            ->assertJsonPath('devolvido_por.militar.id', $this->c['efetivo']->id);
        $this->assertSame(['DISPONIVEL', 'INSERVIVEL'], [ItemIndividual::find($a)->situacao, ItemIndividual::find($a)->estado_conservacao]);

        $baixa($a)->assertCreated();
        $this->assertSame('BAIXADO', ItemIndividual::find($a)->situacao);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'estoque.baixa']);
        $this->getJson('/api/v1/stock/individual-items?q=BX-2')->assertJsonPath('data.0.pode_baixar', true)->assertJsonPath('data.0.pode_editar', true);
        $this->getJson('/api/v1/stock/individual-items?q=BX-1')->assertJsonPath('data.0.pode_baixar', false)->assertJsonPath('data.0.pode_editar', false);
    }

    public function test_edicao_de_item_audita_antes_e_depois_e_recusa_serie_duplicada(): void
    {
        $this->como($this->c['armeiro']);
        $tipo = TipoMaterial::create(['categoria_id' => $this->c['matInd']->categoria_id, 'nome' => 'Colete teste', 'perfil' => 'COLETE', 'ativo' => true]);
        $colete = Material::factory()->create(['tipo_id' => $tipo->id]);
        foreach (['CL-1', 'CL-2'] as $s) {
            $this->entradaPelaGestao(['tipo' => 'ENTRADA', 'local_destino_id' => $this->c['localAlfa']->id, 'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-C',
                'itens' => [['material_id' => $colete->id, 'numero_serie' => $s, 'tamanho' => 'M', 'nivel_protecao' => 'IIIA', 'fabricado_em' => '2024-01-10', 'validade' => '2029-01-10']]])->assertCreated();
        }
        $item = ItemIndividual::where('numero_serie', 'CL-1')->first();
        $url = "/api/v1/stock/individual-items/{$item->id}";

        $this->patchJson($url, ['validade' => '2030-06-30', 'tamanho' => 'G', 'numero_serie' => 'CL-1A', 'estado_conservacao' => 'REGULAR', 'motivo' => 'Etiqueta conferida no recebimento'])
            ->assertOk()->assertJsonPath('identificador', $item->identificador)->assertJsonPath('validade', '2030-06-30');
        $novo = $item->fresh();
        $this->assertSame(['2030-06-30', 'G', 'CL-1A', 'REGULAR', $item->identificador], [$novo->validade->format('Y-m-d'), $novo->tamanho, $novo->numero_serie, $novo->estado_conservacao, $novo->identificador]);
        $ev = json_decode(DB::table('eventos_auditoria')->where('acao', 'item.editado')->value('dados'), true);
        $this->assertSame(['2029-01-10', 'CL-1', 'M'], [$ev['antes']['validade'], $ev['antes']['numero_serie'], $ev['antes']['tamanho']]);
        $this->assertSame(['2030-06-30', 'Etiqueta conferida no recebimento'], [$ev['depois']['validade'], $ev['motivo']]);

        $this->patchJson($url, ['numero_serie' => 'CL-2', 'motivo' => 'Teste de duplicidade'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['numero_serie']]]);
        $this->patchJson($url, ['identificador' => 'EPI-999999', 'motivo' => 'Tentar trocar o código'])->assertUnprocessable();
        $this->patchJson($url, ['validade' => '2020-01-01', 'motivo' => 'Validade antes da fabricação'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['validade']]]);
        $this->patchJson($url, ['tamanho' => null, 'motivo' => 'Colete exige tamanho'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['tamanho']]]);
        $this->patchJson($url, ['validade' => '2030-06-30', 'motivo' => 'Sem mudança nenhuma'])->assertUnprocessable()->assertJsonPath('error.code', 'SEM_ALTERACAO');
        $this->patchJson($url, ['tamanho' => 'P'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['motivo']]]);
        $this->assertSame(1, DB::table('eventos_auditoria')->where('acao', 'item.editado')->count());

        // Perfil geral: validade não se aplica. Sem estoque.editar: 403; fora do escopo: 404.
        [$geral] = $this->itens('GER-1');
        $this->patchJson("/api/v1/stock/individual-items/$geral", ['validade' => '2030-01-01', 'motivo' => 'Não se aplica ao tipo'])->assertUnprocessable();
        $this->como($this->c['operadorAlfa1']);
        $this->patchJson($url, ['tamanho' => 'P', 'motivo' => 'Sem permissão para editar'])->assertNotFound(); // ALFA-1 não vê ALFA
        $this->como($this->usuarioCom('OPERADOR', $this->c['alfa']->id));
        $this->patchJson($url, ['tamanho' => 'P', 'motivo' => 'Sem permissão para editar'])->assertForbidden();
        $this->como($this->c['gestorBravo']);
        $this->patchJson($url, ['tamanho' => 'P', 'motivo' => 'Fora do escopo da unidade'])->assertNotFound();
    }

    public function test_edicao_de_validade_e_codigo_do_lote(): void
    {
        $this->como($this->c['armeiro']);
        $lote = $this->lote(20, 'L-VAL');
        $this->lote(5, 'L-OUTRO');
        $url = "/api/v1/stock/lots/$lote";
        $this->patchJson($url, ['validade' => '2031-12-31', 'motivo' => 'Validade lida errada na entrada'])->assertOk()->assertJsonPath('validade', '2031-12-31');
        $this->assertSame('2031-12-31', DB::table('lotes')->where('id', $lote)->value('validade'));
        $ev = json_decode(DB::table('eventos_auditoria')->where('acao', 'lote.editado')->value('dados'), true);
        $this->assertSame([null, '2031-12-31'], [$ev['antes']['validade'], $ev['depois']['validade']]);
        $this->patchJson($url, ['codigo' => 'L-OUTRO', 'motivo' => 'Código já usado por outro lote'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['codigo']]]);
        $this->patchJson($url, ['codigo' => 'L-VAL-2', 'motivo' => 'Código corrigido conforme nota'])->assertOk()->assertJsonPath('codigo', 'L-VAL-2');
        $this->assertSame(20, $this->saldo($lote)); // saldo e razão intactos

        $this->como($this->usuarioCom('OPERADOR', $this->c['alfa']->id));
        $this->patchJson($url, ['validade' => '2032-01-01', 'motivo' => 'Sem permissão de edição'])->assertForbidden();
        $this->como($this->c['gestorBravo']);
        $this->patchJson($url, ['validade' => '2032-01-01', 'motivo' => 'Fora do escopo da unidade'])->assertNotFound();
    }

    public function test_ajuste_de_quantidade_para_cima_para_baixo_reserva_e_idempotencia(): void
    {
        $this->como($this->c['armeiro']);
        $lote = $this->lote(50);
        $this->mov(['tipo' => 'RESERVA', 'local_origem_id' => $this->c['localAlfa']->id, 'justificativa' => 'Reserva para evento', 'itens' => [['lote_id' => $lote, 'quantidade' => 10]]])->assertCreated();
        $this->entrega([['lote_id' => $lote, 'quantidade' => 5]])->assertCreated(); // com o efetivo: já fora do saldo (45)
        $ajusta = fn (int $qtd, array $extra = [], ?array $chave = null) => $this->withHeaders($chave ?? $this->chave())->postJson('/api/v1/stock/adjustments',
            ['local_id' => $this->c['localAlfa']->id, 'lote_id' => $lote, 'quantidade' => $qtd, 'motivo' => 'Contagem física da reserva'] + $extra);

        // Para cima (+15), idempotente.
        $chave = $this->chave();
        $m = $ajusta(60, ['quantidade_atual' => 45], $chave)->assertCreated()->assertJsonPath('tipo', 'AJUSTE')->assertJsonPath('local_destino.id', $this->c['localAlfa']->id)->json('id');
        $ajusta(60, ['quantidade_atual' => 45], $chave)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $m);
        $ajusta(61, ['quantidade_atual' => 45], $chave)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCIA_CONFLITO');
        $this->assertSame(60, $this->saldo($lote));
        $this->assertDatabaseHas('movimentacao_itens', ['movimentacao_id' => $m, 'lote_id' => $lote, 'quantidade' => 15]);
        $ev = json_decode(DB::table('eventos_auditoria')->where('acao', 'estoque.ajuste')->value('dados'), true);
        $this->assertSame([45, 60, 15], [$ev['antes'], $ev['depois'], $ev['diferenca']]);

        // Para baixo (−30): sai do local de origem.
        $ajusta(30)->assertCreated()->assertJsonPath('local_origem.id', $this->c['localAlfa']->id)->assertJsonPath('itens.0.quantidade', 30);
        $this->assertSame(30, $this->saldo($lote));

        // Recusas: abaixo da reserva (10), saldo mudou desde a tela, sem mudança, sem motivo.
        $ajusta(9)->assertStatus(409)->assertJsonPath('error.code', 'ABAIXO_DA_RESERVA');
        $ajusta(20, ['quantidade_atual' => 45])->assertStatus(409)->assertJsonPath('error.code', 'SALDO_ALTERADO');
        $ajusta(30)->assertUnprocessable()->assertJsonPath('error.code', 'SEM_ALTERACAO');
        $this->withHeaders($this->chave())->postJson('/api/v1/stock/adjustments', ['local_id' => $this->c['localAlfa']->id, 'lote_id' => $lote, 'quantidade' => 25])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['motivo']]]);
        $this->withHeaders(['Idempotency-Key' => ''])->postJson('/api/v1/stock/adjustments', ['local_id' => $this->c['localAlfa']->id, 'lote_id' => $lote, 'quantidade' => 25, 'motivo' => 'Sem chave de idempotência'])->assertStatus(428);
        $this->assertSame(30, $this->saldo($lote));
        $this->assertSame(2, Movimentacao::where('tipo', 'AJUSTE')->count());

        // A devolução do que estava com o efetivo volta por cima do saldo ajustado.
        $e = Movimentacao::where('tipo', 'ENTREGA')->value('id');
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e])->assertCreated();
        $this->assertSame(35, $this->saldo($lote));

        // Permissão: operador sem estoque.editar não ajusta. Razão imutável: o AJUSTE não pode ser alterado depois.
        $this->como($this->usuarioCom('OPERADOR', $this->c['alfa']->id));
        $ajusta(40)->assertForbidden();
        $this->expectException(QueryException::class);
        Movimentacao::whereKey($m)->toBase()->update(['justificativa' => 'adulterado']);
    }

    public function test_natureza_equipamento_aparece_como_epi_sem_mudar_o_codigo(): void
    {
        $this->como($this->c['gestorAlfa']);
        $this->entradaPelaGestao($this->entradaLote($this->c['localAlfa'], 7))->assertCreated();
        $this->assertSame('EQUIPAMENTO', DB::table('categorias')->where('codigo', 'CAT-TESTE')->value('natureza'));

        $nat = collect($this->getJson('/api/v1/dashboard')->json('estoque.por_natureza'))->firstWhere('rotulo', 'EQUIPAMENTO');
        $this->assertSame(['EPI', 7], [$nat['nome'], $nat['valor']]);
        $this->getJson('/api/v1/stock/balances?natureza=EQUIPAMENTO')->assertJsonPath('data.0.material.natureza', 'EQUIPAMENTO')
            ->assertJsonPath('data.0.material.natureza_rotulo', 'EPI');
        $csv = $this->get('/api/v1/reports/saldos')->assertOk()->streamedContent();
        $this->assertStringContainsString(';Natureza;', $csv);
        $this->assertStringContainsString(';EPI;', $csv);
        $cats = $this->get('/api/v1/data-transfer/categorias/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('CAT-TESTE', $cats);
        $this->assertStringNotContainsString('EQUIPAMENTO', $cats); // planilha de categorias sai com "EPI" e reimporta (normalizar)

        $this->assertSame(['EQUIPAMENTO', 'MUNICAO', 'ARMAMENTO', 'XYZ'], array_map([Natureza::class, 'normalizar'], ['epi', 'Munição', 'ARMAMENTO', 'xyz']));
    }
}
