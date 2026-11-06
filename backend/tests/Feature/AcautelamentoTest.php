<?php

namespace Tests\Feature;

use App\Models\Acautelamento;
use App\Models\ItemIndividual;
use App\Models\Lote;
use App\Models\Militar;
use App\Models\Papel;
use App\Models\SaldoEstoque;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Entrega/devolução (RF-011, RN-007/008) e acautelamento (RF-015..018, RN-009..011) com regra PROVISÓRIA. */
class AcautelamentoTest extends TestCase
{
    use RefreshDatabase;

    private Militar $efetivo;

    private int $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        config(['sigarb.operacoes_provisorias' => ['ENTREGA', 'DEVOLUCAO', 'ACAUTELAMENTO', 'INSPECAO', 'DESACAUTELAMENTO']]);
        $this->efetivo = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'ARM-1', 'S-ARM-1'))->assertCreated();
        $this->item = ItemIndividual::where('identificador', 'ARM-1')->value('id');
    }

    private function pedir(): string
    {
        return $this->como($this->c['gestorAlfa'])->postJson('/api/v1/acautelamentos/requests', ['militar_id' => $this->efetivo->id, 'item_individual_id' => $this->item, 'justificativa' => 'Serviço operacional'])
            ->assertCreated()->assertJsonPath('estado', 'SOLICITADO')->json('codigo');
    }

    private function versao(string $codigo): int
    {
        return Acautelamento::where('codigo', $codigo)->value('versao');
    }

    public function test_ciclo_completo_com_segregacao_carteira_inspecao_e_desacautelamento(): void
    {
        $cod = $this->pedir();
        // Segunda solicitação para o mesmo item é recusada (um em curso por item)
        $this->postJson('/api/v1/acautelamentos/requests', ['militar_id' => $this->efetivo->id, 'item_individual_id' => $this->item, 'justificativa' => 'Duplicado'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ITEM_EM_ACAUTELAMENTO');
        // Gestor não tem acautelamento.aprovar; aprovador sim, e não pode ser quem pediu
        $this->postJson("/api/v1/acautelamentos/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertForbidden();
        $this->como($this->c['aprovador'])->getJson("/api/v1/acautelamentos/$cod")->assertJsonPath('acoes.decidir', true);
        $this->postJson("/api/v1/acautelamentos/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 99])->assertStatus(409)->assertJsonPath('error.code', 'VERSAO_DESATUALIZADA');
        $this->postJson("/api/v1/acautelamentos/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertOk()->assertJsonPath('estado', 'APROVADO');

        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/acautelamentos/$cod/delivery", ['versao' => 2])->assertOk()->assertJsonPath('estado', 'ATIVO');
        $this->assertSame('ACAUTELADO', ItemIndividual::find($this->item)->situacao);
        $this->assertSame(now()->addDays(365)->toDateString(), Acautelamento::where('codigo', $cod)->first()->proxima_inspecao->toDateString());

        // Carteira: token opaco; verificação exige sessão e não expõe matrícula
        $cart = $this->getJson("/api/v1/acautelamentos/$cod/carteira")->assertOk()->json();
        $this->assertSame($this->efetivo->matricula, $cart['efetivo']['matricula']); // gestor tem militares.ver_detalhe
        $this->assertSame(43, strlen($cart['token']));
        $this->getJson('/api/v1/carteiras/'.$cart['token'])->assertJsonPath('valida', true)->assertJsonMissingPath('matricula');
        $this->como($this->c['gestorBravo'])->getJson("/api/v1/acautelamentos/$cod")->assertNotFound();

        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/acautelamentos/$cod/inspections", ['resultado' => 'NAO_CONFORME', 'versao' => 3])->assertStatus(422);
        $this->postJson("/api/v1/acautelamentos/$cod/inspections", ['resultado' => 'CONFORME', 'versao' => 3])->assertOk()->assertJsonCount(1, 'inspecoes');

        $this->postJson("/api/v1/acautelamentos/$cod/return", ['estado_conservacao' => 'REGULAR', 'divergencias' => 'Riscos na empunhadura', 'versao' => 4])
            ->assertOk()->assertJsonPath('estado', 'ENCERRADO');
        $i = ItemIndividual::find($this->item);
        $this->assertSame(['DISPONIVEL', 'REGULAR'], [$i->situacao, $i->estado_conservacao]);
        $this->getJson('/api/v1/carteiras/'.$cart['token'])->assertJsonPath('valida', false);
        $this->assertDatabaseHas('movimentacoes', ['tipo' => 'DEVOLUCAO', 'militar_id' => $this->efetivo->id, 'provisoria' => true]);
        foreach (['solicitado', 'aprovado', 'entregue', 'inspecionado', 'encerrado', 'carteira_emitida'] as $e) {
            $this->assertDatabaseHas('eventos_auditoria', ['acao' => "acautelamento.$e"]);
        }
        // Inspeção é imutável
        $this->expectException(QueryException::class);
        DB::table('inspecoes')->update(['resultado' => 'NAO_CONFORME']);
    }

    public function test_solicitante_nao_decide_rejeicao_exige_justificativa_e_bloqueio_por_padrao(): void
    {
        $this->c['aprovador']->atribuicoes()->create(['papel_id' => Papel::where('codigo', 'GESTOR')->value('id'), 'unidade_id' => $this->c['alfa']->id]);
        $this->como($this->c['aprovador']);
        $cod = $this->postJson('/api/v1/acautelamentos/requests', ['militar_id' => $this->efetivo->id, 'item_individual_id' => $this->item, 'justificativa' => 'Pedido próprio'])->assertCreated()->json('codigo');
        $this->postJson("/api/v1/acautelamentos/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertForbidden()->assertJsonPath('error.code', 'SEGREGACAO_FUNCOES');
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/acautelamentos/$cod/cancel", ['justificativa' => 'Não será necessário', 'versao' => 1])->assertOk()->assertJsonPath('estado', 'CANCELADO');

        $cod2 = $this->pedir();
        $outro = $this->usuarioCom('APROVADOR', $this->c['alfa']->id);
        $this->como($outro)->postJson("/api/v1/acautelamentos/$cod2/decision", ['decisao' => 'REJEITAR', 'versao' => 1])->assertStatus(422);
        $this->postJson("/api/v1/acautelamentos/$cod2/decision", ['decisao' => 'REJEITAR', 'justificativa' => 'Sem habilitação', 'versao' => 1])->assertJsonPath('estado', 'REJEITADO');

        config(['sigarb.operacoes_provisorias' => []]);
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/acautelamentos/requests', [])->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
    }

    public function test_entrega_recusa_item_transferido_apos_pedido(): void
    {
        $cod = $this->pedir();
        $this->como($this->usuarioCom('APROVADOR', $this->c['alfa']->id))->postJson("/api/v1/acautelamentos/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertOk();
        ItemIndividual::whereKey($this->item)->update(['local_estoque_id' => $this->c['localBravo']->id]);
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/acautelamentos/$cod/delivery", ['versao' => 2])->assertStatus(409)->assertJsonPath('error.code', 'ITEM_FORA_DA_UNIDADE');
    }

    public function test_entrega_com_e_sem_previsao_de_retorno(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 40, 'L-E'))->assertCreated();
        $lote = Lote::where('codigo', 'L-E')->value('id');
        $base = ['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa']->id, 'militar_id' => $this->efetivo->id, 'justificativa' => 'Uso em serviço'];
        // Com previsão: pendente até devolução, que credita o lote de volta
        $e = $this->withHeaders($this->chave())->postJson('/api/v1/movements', $base + ['previsao_retorno' => now()->addDays(5)->toDateString(), 'itens' => [['item_individual_id' => $this->item], ['lote_id' => $lote, 'quantidade' => 10]]])
            ->assertCreated()->assertJsonPath('militar.id', $this->efetivo->id);
        $this->assertSame('ENTREGUE', ItemIndividual::find($this->item)->situacao);
        $this->getJson('/api/v1/movements?pendente=1')->assertJsonPath('meta.total', 1);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'DEVOLUCAO', 'vinculo_id' => $e->json('id')])->assertCreated();
        $this->assertSame(40, (int) SaldoEstoque::where('lote_id', $lote)->value('quantidade'));
        $this->assertSame('DISPONIVEL', ItemIndividual::find($this->item)->situacao);
        // Sem previsão: consumo, sem devolução possível
        $c = $this->withHeaders($this->chave())->postJson('/api/v1/movements', $base + ['itens' => [['lote_id' => $lote, 'quantidade' => 15]]])->assertCreated();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'DEVOLUCAO', 'vinculo_id' => $c->json('id')])->assertStatus(422)->assertJsonPath('error.code', 'SEM_RETORNO');
        $this->assertSame(25, (int) SaldoEstoque::where('lote_id', $lote)->value('quantidade'));
        // Efetivo fora do escopo
        $fora = Militar::factory()->create(['unidade_id' => $this->c['bravo']->id]);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['militar_id' => $fora->id, 'itens' => [['lote_id' => $lote, 'quantidade' => 1]]] + $base)->assertForbidden();
    }
}
