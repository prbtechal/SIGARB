<?php

namespace Tests\Feature;

use App\Models\ItemIndividual;
use App\Models\Movimentacao;
use App\Models\SaldoEstoque;
use App\Services\EstoqueService;
use App\Support\SigarbException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RF-010, RF-011, RF-013, RF-027, RN-001..005, RN-012, RN-017, RN-018, RN-019, UC-02, UC-04. */
class EstoqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    private function saldo(int $localId): int
    {
        return (int) SaldoEstoque::where('local_estoque_id', $localId)->sum('quantidade');
    }

    public function test_entrada_de_lote_cria_razao_saldo_e_auditoria(): void
    {
        $r = $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())
            ->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 250, 'L-1', '2030-01-31'))
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('tipo', 'ENTRADA')->assertJsonPath('responsavel.id', $this->c['gestorAlfa']->id)
            ->assertJsonPath('itens.0.lote.validade', '2030-01-31')->assertJsonPath('pode_estornar', true);
        $this->assertSame(250, $this->saldo($this->c['localAlfa']->id));
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'estoque.entrada', 'recurso_id' => $r->json('id'), 'unidade_id' => $this->c['alfa']->id]);
        // RN-004: mesmo lote com validade divergente é rejeitado
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 1, 'L-1', '2031-01-01'))->assertStatus(422);
    }

    public function test_entrada_individual_exige_identificador_unico_e_respeita_modo(): void
    {
        $this->como($this->c['operadorAlfa1'])->withHeaders($this->chave())
            ->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa1'], 'PAT-1', 'S-1'))->assertCreated();
        $this->assertDatabaseHas('itens_individuais', ['identificador' => 'PAT-1', 'situacao' => 'DISPONIVEL', 'local_estoque_id' => $this->c['localAlfa1']->id]);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa1'], 'PAT-1'))
            ->assertStatus(422)->assertJsonPath('error.code', 'IDENTIFICADOR_DUPLICADO');
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa1'], 'PAT-2', 'S-1'))
            ->assertStatus(422)->assertJsonPath('error.code', 'IDENTIFICADOR_DUPLICADO');
        $mist = $this->entradaIndividual($this->c['localAlfa1'], 'PAT-3');
        $mist['itens'][0]['quantidade'] = 5;
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $mist)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['itens.0.quantidade']]]);
        $semLote = $this->entradaLote($this->c['localAlfa1'], 5);
        unset($semLote['itens'][0]['lote_codigo']);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $semLote)->assertStatus(422);
        $dup = $this->entradaIndividual($this->c['localAlfa1'], 'PAT-9');
        $dup['itens'][] = $dup['itens'][0];
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $dup)->assertStatus(422);
        $this->assertSame(1, Movimentacao::count());
    }

    public function test_entrada_exige_documento_origem_e_chave_de_idempotencia(): void
    {
        $this->como($this->c['gestorAlfa']);
        $d = $this->entradaLote($this->c['localAlfa'], 10);
        $this->postJson('/api/v1/movements', $d)->assertStatus(428)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_OBRIGATORIA');
        unset($d['documento_referencia'], $d['origem_descricao']);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $d)->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['documento_referencia', 'origem_descricao']]]);
    }

    public function test_repeticao_com_mesma_chave_nao_duplica_e_chave_reusada_com_outro_conteudo_e_rejeitada(): void
    {
        $h = $this->chave();
        $d = $this->entradaLote($this->c['localAlfa'], 40);
        $a = $this->como($this->c['gestorAlfa'])->withHeaders($h)->postJson('/api/v1/movements', $d)->assertCreated();
        $b = $this->withHeaders($h)->postJson('/api/v1/movements', $d)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertSame(40, $this->saldo($this->c['localAlfa']->id));
        $d['itens'][0]['quantidade'] = 41;
        $this->withHeaders($h)->postJson('/api/v1/movements', $d)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCIA_CONFLITO');
        $this->como($this->c['admin'])->withHeaders($h)->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 40))->assertForbidden();
    }

    public function test_entrada_fora_do_escopo_e_por_perfil_sem_permissao_e_negada(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localBravo'], 5))->assertForbidden();
        $this->como($this->c['operadorAlfa1'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->assertForbidden();
        $this->como($this->c['auditor'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->assertForbidden();
        $this->como($this->c['aprovador'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->assertForbidden();
        $this->assertDatabaseCount('movimentacoes', 0);
    }

    public function test_tipos_sem_politica_aprovada_estao_bloqueados(): void
    {
        $this->como($this->c['gestorAlfa']);
        foreach (['SAIDA', 'ENTREGA', 'DEVOLUCAO', 'TRANSFERENCIA', 'RESERVA', 'BAIXA'] as $tipo) {
            $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => $tipo] + $this->entradaLote($this->c['localAlfa'], 1))
                ->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
        }
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'HACK'])->assertStatus(422);
        $this->assertDatabaseCount('movimentacoes', 0);
    }

    public function test_estorno_de_lote_preserva_original_e_impede_segundo_estorno(): void
    {
        $orig = $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 30))->json('id');
        $this->withHeaders($this->chave())->postJson("/api/v1/movements/$orig/reversal", ['justificativa' => 'curta'])->assertStatus(422);
        $e = $this->withHeaders($this->chave())->postJson("/api/v1/movements/$orig/reversal", ['justificativa' => 'Lançamento em duplicidade (teste)'])
            ->assertCreated()->assertJsonPath('tipo', 'ESTORNO')->assertJsonPath('estorno_de_id', $orig);
        $this->assertSame(0, $this->saldo($this->c['localAlfa']->id));
        $this->assertDatabaseHas('movimentacoes', ['id' => $orig, 'tipo' => 'ENTRADA']);
        $this->getJson("/api/v1/movements/$orig")->assertJsonPath('estornada', true)->assertJsonPath('estorno_id', $e->json('id'))->assertJsonPath('pode_estornar', false);
        $this->withHeaders($this->chave())->postJson("/api/v1/movements/$orig/reversal", ['justificativa' => 'Segunda tentativa de estorno'])
            ->assertStatus(409)->assertJsonPath('error.code', 'JA_ESTORNADA');
        $this->withHeaders($this->chave())->postJson('/api/v1/movements/'.$e->json('id').'/reversal', ['justificativa' => 'Estorno de estorno não permitido'])
            ->assertStatus(422)->assertJsonPath('error.code', 'ESTORNO_INVALIDO');
    }

    public function test_estorno_individual_e_permissao_de_estorno(): void
    {
        $orig = $this->como($this->c['operadorAlfa1'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa1'], 'PAT-E1'))->json('id');
        $this->withHeaders($this->chave())->postJson("/api/v1/movements/$orig/reversal", ['justificativa' => 'Operador não pode estornar'])->assertForbidden();
        $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson("/api/v1/movements/$orig/reversal", ['justificativa' => 'Fora do escopo do gestor'])->assertForbidden();
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson("/api/v1/movements/$orig/reversal", ['justificativa' => 'Identificador digitado errado'])->assertCreated();
        $this->assertSame('ESTORNADO', ItemIndividual::where('identificador', 'PAT-E1')->value('situacao'));
    }

    public function test_estorno_impedido_quando_item_nao_esta_mais_disponivel(): void
    {
        $orig = $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'PAT-M1'))->json('id');
        ItemIndividual::where('identificador', 'PAT-M1')->update(['situacao' => 'MANUTENCAO']);
        $this->withHeaders($this->chave())->postJson("/api/v1/movements/$orig/reversal", ['justificativa' => 'Tentativa com item indisponível'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ESTORNO_IMPEDIDO');
        $this->assertSame(1, Movimentacao::count()); // transação revertida por completo
    }

    public function test_debito_nunca_gera_saldo_negativo_e_considera_reserva(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 10));
        $saldo = SaldoEstoque::first();
        $saldo->update(['quantidade_reservada' => 4]);
        $svc = app(EstoqueService::class);
        try {
            DB::transaction(fn () => $svc->debitarLote($this->c['localAlfa']->id, $saldo->lote_id, 7));
            $this->fail('Deveria recusar débito acima do disponível (10 − 4 reservados).');
        } catch (SigarbException $e) {
            $this->assertSame('SALDO_INSUFICIENTE', $e->codigo);
            $this->assertSame(6, $e->detalhes['disponivel']);
        }
        DB::transaction(fn () => $svc->debitarLote($this->c['localAlfa']->id, $saldo->lote_id, 6));
        $this->assertSame(4, $saldo->fresh()->quantidade);
    }

    public function test_banco_rejeita_saldo_negativo_reserva_maior_que_saldo_e_alteracao_do_razao(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 3));
        $falhas = 0;
        foreach ([
            fn () => DB::update('UPDATE saldos_estoque SET quantidade = quantidade - 5'),
            fn () => DB::update('UPDATE saldos_estoque SET quantidade_reservada = 99'),
            fn () => DB::update("UPDATE movimentacoes SET justificativa = 'adulterado'"),
            fn () => DB::delete('DELETE FROM movimentacao_itens'),
            fn () => DB::insert('INSERT INTO movimentacao_itens (movimentacao_id, quantidade) VALUES (?, 1)', [Movimentacao::value('id')]),
        ] as $tentativa) {
            try {
                DB::transaction($tentativa);
            } catch (QueryException) {
                $falhas++;
            }
        }
        $this->assertSame(5, $falhas);
        $this->assertSame(3, $this->saldo($this->c['localAlfa']->id));
    }

    public function test_consulta_de_saldos_unifica_lote_e_individual_dentro_do_escopo(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 100));
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa1'], 'PAT-S1'));
        $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localBravo'], 7, 'L-B'));

        $alfa = $this->como($this->c['gestorAlfa'])->getJson('/api/v1/stock/balances')->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertEqualsCanonicalizing([100, 1], array_column($alfa, 'quantidade'));
        $this->assertNotContains($this->c['localBravo']->id, array_column(array_column($alfa, 'local'), 'id'));
        $this->como($this->c['operadorAlfa1'])->getJson('/api/v1/stock/balances')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.material.modo_controle', 'INDIVIDUAL');
        $this->como($this->c['auditor'])->getJson('/api/v1/stock/balances')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/stock/balances?q=L-B')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.lote.codigo', 'L-B');
    }

    public function test_historico_de_movimentacoes_por_escopo_e_linha_do_tempo_do_item(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'PAT-H1'));
        $bravo = $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localBravo'], 2))->json('id');
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/movements')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/movements/$bravo")->assertForbidden();
        $item = ItemIndividual::where('identificador', 'PAT-H1')->value('id');
        $this->getJson("/api/v1/movements?item_individual_id=$item")->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/stock/individual-items?q=PAT-H')->assertJsonPath('data.0.identificador', 'PAT-H1');
    }
}
