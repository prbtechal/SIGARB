<?php

namespace Tests\Feature;

use App\Models\ItemIndividual;
use App\Models\Lote;
use App\Models\SaldoEstoque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Saída, baixa, transferência, reserva e manutenção com regra PROVISÓRIA (RF-011/012/019/021, RN-006/012/013/014/015). */
class OperacoesProvisoriasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        config(['sigarb.operacoes_provisorias' => ['SAIDA', 'BAIXA', 'TRANSFERENCIA', 'RESERVA', 'MANUTENCAO']]);
    }

    private function estoqueInicial(): array
    {
        $this->como($this->c['gestorAlfa']);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 100, 'L-9'))->assertCreated();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'PAT-10'))->assertCreated();

        return [Lote::where('codigo', 'L-9')->value('id'), ItemIndividual::where('identificador', 'PAT-10')->value('id')];
    }

    private function op(string $tipo, array $itens, array $extra = []): array
    {
        return ['tipo' => $tipo, 'local_origem_id' => $this->c['localAlfa']->id, 'justificativa' => 'Motivo de teste', 'documento_referencia' => 'DOC-OP-1', 'itens' => $itens] + $extra;
    }

    private function saldo(int $local, int $lote): SaldoEstoque
    {
        return SaldoEstoque::where('local_estoque_id', $local)->where('lote_id', $lote)->firstOrFail();
    }

    public function test_desligada_por_padrao_responde_bloqueada_sem_efeito(): void
    {
        [$lote] = $this->estoqueInicial();
        // CP-14: todas decididas. Simula TRANSFERENCIA ainda pendente: bloqueada e regra provisória desligada.
        $this->bloquear('TRANSFERENCIA');
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('TRANSFERENCIA', [['lote_id' => $lote, 'quantidade' => 1]], ['local_destino_id' => $this->c['localAlfa1']->id]))
            ->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
        $this->postJson('/api/v1/transfers', [])->assertStatus(409);
        $this->assertSame(100, $this->saldo($this->c['localAlfa']->id, $lote)->quantidade);
        $ops = collect($this->getJson('/api/v1/operations')->json('data'))->keyBy('codigo');
        $this->assertSame('BLOQUEADA', $ops['TRANSFERENCIA']['estado']);
        $this->assertSame('HABILITADA', $ops['SAIDA']['estado']);
    }

    public function test_saida_debita_marca_item_e_audita_como_decisao_do_responsavel(): void
    {
        [$lote, $item] = $this->estoqueInicial();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('SAIDA', [['lote_id' => $lote, 'quantidade' => 30], ['item_individual_id' => $item]], ['destinacao' => 'Destino fictício']))
            ->assertCreated()->assertJsonPath('provisoria', false); // CP-13: decisão do responsável, não mais regra provisória
        $this->assertSame(70, $this->saldo($this->c['localAlfa']->id, $lote)->quantidade);
        $this->assertSame('FORA_DO_ESTOQUE', ItemIndividual::find($item)->situacao);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'estoque.saida', 'resultado' => 'SUCESSO']);
        // RN-002: sem saldo negativo; item já fora não sai de novo
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('SAIDA', [['lote_id' => $lote, 'quantidade' => 71]], ['destinacao' => 'Destino X']))
            ->assertStatus(409)->assertJsonPath('error.code', 'SALDO_INSUFICIENTE');
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('SAIDA', [['item_individual_id' => $item]], ['destinacao' => 'Destino X']))
            ->assertStatus(409)->assertJsonPath('error.code', 'ITEM_INDISPONIVEL');
        // RN-006: motivo, destinação e documento obrigatórios
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'SAIDA', 'local_origem_id' => $this->c['localAlfa']->id, 'itens' => [['lote_id' => $lote, 'quantidade' => 1]]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['justificativa', 'destinacao', 'documento_referencia']]]);
    }

    public function test_reserva_reduz_disponivel_e_liberacao_e_unica(): void
    {
        [$lote, $item] = $this->estoqueInicial();
        $res = $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('RESERVA', [['lote_id' => $lote, 'quantidade' => 60], ['item_individual_id' => $item]]))->assertCreated();
        $s = $this->saldo($this->c['localAlfa']->id, $lote);
        $this->assertSame([100, 60], [$s->quantidade, $s->quantidade_reservada]);
        // RN-012: saída respeita o reservado
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('SAIDA', [['lote_id' => $lote, 'quantidade' => 41]], ['destinacao' => 'Destino X']))->assertStatus(409);
        $this->getJson('/api/v1/movements?pendente=1')->assertJsonPath('meta.total', 1);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'LIBERACAO_RESERVA', 'vinculo_id' => $res->json('id')])->assertCreated();
        $this->assertSame(0, $this->saldo($this->c['localAlfa']->id, $lote)->quantidade_reservada);
        $this->assertSame('DISPONIVEL', ItemIndividual::find($item)->situacao);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'LIBERACAO_RESERVA', 'vinculo_id' => $res->json('id')])
            ->assertStatus(409)->assertJsonPath('error.code', 'JA_CONCLUIDA');
        $this->getJson('/api/v1/movements?pendente=1')->assertJsonPath('meta.total', 0);
    }

    public function test_transferencia_em_dois_tempos_com_recebimento_pelo_destino(): void
    {
        [$lote, $item] = $this->estoqueInicial();
        $env = $this->withHeaders($this->chave())->postJson('/api/v1/transfers', $this->op('TRANSFERENCIA', [['lote_id' => $lote, 'quantidade' => 25], ['item_individual_id' => $item]], ['local_destino_id' => $this->c['localAlfa1']->id]))
            ->assertCreated();
        $this->assertSame(75, $this->saldo($this->c['localAlfa']->id, $lote)->quantidade);
        $this->assertSame('EM_TRANSITO', ItemIndividual::find($item)->situacao);
        // Sem permissão no destino: operador (só estoque.ver/entrada) não recebe
        $this->como($this->c['operadorAlfa1'])->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'RECEBIMENTO', 'vinculo_id' => $env->json('id')])->assertForbidden();
        $this->como($this->c['gestorBravo'])->getJson('/api/v1/movements/'.$env->json('id'))->assertForbidden();
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'RECEBIMENTO', 'vinculo_id' => $env->json('id')])->assertCreated();
        $this->assertSame(25, $this->saldo($this->c['localAlfa1']->id, $lote)->quantidade);
        $i = ItemIndividual::find($item);
        $this->assertSame(['DISPONIVEL', $this->c['localAlfa1']->id], [$i->situacao, $i->local_estoque_id]);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('TRANSFERENCIA', [['lote_id' => $lote, 'quantidade' => 1]], ['local_destino_id' => $this->c['localAlfa']->id]))
            ->assertStatus(422)->assertJsonPath('error.code', 'DESTINO_INVALIDO');
    }

    public function test_manutencao_retorno_e_baixa_com_escopo(): void
    {
        [$lote, $item] = $this->estoqueInicial();
        $man = $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('MANUTENCAO', [['item_individual_id' => $item]]))->assertCreated();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('MANUTENCAO', [['lote_id' => $lote, 'quantidade' => 1]]))->assertStatus(422);
        // RN-015: em manutenção não aparece disponível; pode ser baixado (RN-014)
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('RESERVA', [['item_individual_id' => $item]]))->assertStatus(409);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'RETORNO_MANUTENCAO', 'vinculo_id' => $man->json('id')])->assertCreated();
        $this->assertSame('DISPONIVEL', ItemIndividual::find($item)->situacao);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('BAIXA', [['item_individual_id' => $item], ['lote_id' => $lote, 'quantidade' => 5]]))->assertCreated();
        $this->assertSame('BAIXADO', ItemIndividual::find($item)->situacao);
        $this->assertSame(95, $this->saldo($this->c['localAlfa']->id, $lote)->quantidade);
        // Fora do escopo e sem permissão
        $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('BAIXA', [['lote_id' => $lote, 'quantidade' => 1]]))->assertForbidden();
        $this->como($this->c['operadorAlfa1'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->op('BAIXA', [['lote_id' => $lote, 'quantidade' => 1]]))->assertForbidden();
    }

    public function test_idempotencia_nas_operacoes(): void
    {
        [$lote] = $this->estoqueInicial();
        $h = $this->chave();
        $d = $this->op('RESERVA', [['lote_id' => $lote, 'quantidade' => 10]]);
        $a = $this->withHeaders($h)->postJson('/api/v1/movements', $d)->assertCreated();
        $this->withHeaders($h)->postJson('/api/v1/movements', $d)->assertOk()->assertJsonPath('id', $a->json('id'));
        $this->assertSame(10, $this->saldo($this->c['localAlfa']->id, $lote)->quantidade_reservada);
    }
}
