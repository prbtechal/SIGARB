<?php

namespace Tests\Feature;

use App\Models\ItemIndividual;
use App\Models\Militar;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Decisão do responsável (2026-11-18): situação do material RELATIVA À ENTREGA (`situacao_na_entrega`), igual para item e lote,
 * calculada pelo vínculo devolução ↔ entrega — nunca a situação atual do item no estoque ("Disponível").
 */
class SituacaoNaEntregaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $armeiro;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->cenario();
        $this->armeiro = $this->usuarioCom('ARMEIRO', $this->c['alfa']->id);
        $this->como($this->armeiro);
        $this->entradaPelaGestao($this->entradaIndividual($this->c['localAlfa'], 'SER-1'))->assertCreated();
        $this->entradaPelaGestao($this->entradaLote($this->c['localAlfa'], 50, 'L-1'))->assertCreated();
    }

    private function mov(array $corpo): TestResponse
    {
        return $this->withHeaders($this->chave())->postJson('/api/v1/movements', $corpo);
    }

    private function entrega(Militar $m, array $itens, bool $retorno = true): int
    {
        return $this->mov(array_filter(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa']->id, 'militar_id' => $m->id, 'motivo_codigo' => 'PATRULHA',
            'previsao_retorno' => $retorno ? now()->addDays(3)->toDateString() : null, 'itens' => $itens]))->assertCreated()->json('id');
    }

    /** @return array<string, array> situação na entrega por "i<id>" / "l<id>" */
    private function situacoes(int $mov): array
    {
        return collect($this->getJson("/api/v1/movements/$mov")->assertOk()->json('itens'))
            ->mapWithKeys(fn ($i) => [($i['item']['id'] ?? null) ? 'i'.$i['item']['id'] : 'l'.$i['lote']['id'] => $i['situacao_na_entrega']])->all();
    }

    public function test_situacao_relativa_a_entrega_para_item_e_lote(): void
    {
        $a = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'categoria_efetivo' => 'R1']);
        $b = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'categoria_efetivo' => 'R1']);
        $item = ItemIndividual::where('numero_serie', 'SER-1')->value('id');
        $lote = (int) DB::table('lotes')->where('codigo', 'L-1')->value('id');

        $e1 = $this->entrega($a, [['item_individual_id' => $item], ['lote_id' => $lote, 'quantidade' => 10]]);
        $s = $this->situacoes($e1);
        $this->assertSame(['ENTREGUE', 'Entregue'], [$s["i$item"]['codigo'], $s["i$item"]['rotulo']]);
        $this->assertSame(['ENTREGUE', 'Entregue'], [$s["l$lote"]['codigo'], $s["l$lote"]['rotulo']]);
        // Telas de entrega/devolução (linhas ainda com o efetivo) usam o mesmo campo.
        $linhas = collect($this->getJson('/api/v1/stock/linked')->assertOk()->json('data.0.itens'));
        $this->assertSame(['Entregue', 'Entregue'], $linhas->pluck('situacao_na_entrega.rotulo')->all());

        // Devolve o item e 4 do lote: item "Devolvido" (não "Disponível"), lote "Devolvido parcialmente: 4 de 10".
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e1, 'itens' => [['item_individual_id' => $item], ['lote_id' => $lote, 'quantidade' => 4]]])->assertCreated();
        $s = $this->situacoes($e1);
        $this->assertSame(['DEVOLVIDO', 'Devolvido'], [$s["i$item"]['codigo'], $s["i$item"]['rotulo']]);
        $this->assertSame('hoje: disponível na '.$this->c['localAlfa']->codigo, $s["i$item"]['hoje']);
        $this->assertSame(['DEVOLVIDO_PARCIAL', 'Devolvido parcialmente: 4 de 10'], [$s["l$lote"]['codigo'], $s["l$lote"]['rotulo']]);
        $this->assertSame('Devolvido parcialmente: 4 de 10', collect($this->getJson('/api/v1/stock/linked')->json('data.0.itens'))->firstWhere('lote_id', $lote)['situacao_na_entrega']['rotulo']);

        // O mesmo item reentregue a outro efetivo continua "Devolvido" na entrega antiga.
        $e2 = $this->entrega($b, [['item_individual_id' => $item]]);
        $this->assertSame('DEVOLVIDO', $this->situacoes($e1)["i$item"]['codigo']);
        $this->assertSame('ENTREGUE', $this->situacoes($e2)["i$item"]['codigo']);

        // Volta da segunda entrega e é baixado: "Baixado após devolução" na segunda; a primeira segue "Devolvido".
        $this->mov(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e2])->assertCreated();
        $this->mov(['tipo' => 'BAIXA', 'local_origem_id' => $this->c['localAlfa']->id, 'justificativa' => 'Danificado no serviço', 'documento_referencia' => 'DOC-BX',
            'itens' => [['item_individual_id' => $item]]])->assertCreated();
        $this->assertSame(['BAIXADO_APOS_DEVOLUCAO', 'Baixado após devolução'], [$this->situacoes($e2)["i$item"]['codigo'], $this->situacoes($e2)["i$item"]['rotulo']]);
        $this->assertSame('DEVOLVIDO', $this->situacoes($e1)["i$item"]['codigo']);

        // Entrega sem retorno: "Consumido".
        $e3 = $this->entrega($a, [['lote_id' => $lote, 'quantidade' => 5]], false);
        $this->assertSame(['CONSUMIDO', 'Consumido'], [$this->situacoes($e3)["l$lote"]['codigo'], $this->situacoes($e3)["l$lote"]['rotulo']]);
    }
}
