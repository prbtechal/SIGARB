<?php

namespace Tests\Feature;

use App\Models\Acautelamento;
use App\Models\AtribuicaoPapel;
use App\Models\Categoria;
use App\Models\EventoAuditoria;
use App\Models\ItemIndividual;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Movimentacao;
use App\Models\Notificacao;
use App\Models\Papel;
use App\Models\PedidoMaterial;
use App\Models\TipoMaterial;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Portal do efetivo (decisão do responsável, 2026-11-18): primeiro acesso pelo CPF, pedido prévio de material,
 * aprovação antecipada pelo armeiro e entrega presencial (ENTREGA ou, para arma de fogo, ACAUTELAMENTO).
 */
class PortalEfetivoTest extends TestCase
{
    use RefreshDatabase;

    private const CPF = '52998224725'; // CPF de exemplo (sintético, dígitos válidos)

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->cenario();
        $this->c['armeiro'] = $this->usuarioCom('ARMEIRO', $this->c['alfa']->id);
        $this->c['ef'] = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'PM_ATIVA', 'cpf' => self::CPF]);
        $this->c['r1'] = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => 'R1']);
        $this->c['semTipo'] = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'categoria_efetivo' => null]);
        $armas = Categoria::firstOrCreate(['codigo' => 'ARM-T'], ['nome' => 'Armas teste', 'natureza' => 'ARMAMENTO']);
        $tipo = TipoMaterial::create(['categoria_id' => $armas->id, 'nome' => 'Pistola teste', 'perfil' => 'ARMA_FOGO', 'ativo' => true]);
        $this->c['arma'] = Material::factory()->create(['categoria_id' => $armas->id, 'tipo_id' => $tipo->id, 'nome' => 'Pistola fictícia']);
        $mun = Categoria::firstOrCreate(['codigo' => 'MUN-T'], ['nome' => 'Munição teste', 'natureza' => 'MUNICAO']);
        $this->c['municao'] = Material::factory()->lote()->create(['categoria_id' => $mun->id, 'nome' => 'Munição fictícia']);
    }

    private function contaEfetivo(Militar $m): Usuario
    {
        $u = Usuario::factory()->create(['militar_id' => $m->id]);
        AtribuicaoPapel::create(['usuario_id' => $u->id, 'papel_id' => Papel::where('codigo', 'EFETIVO')->value('id'), 'unidade_id' => $m->unidade_id]);

        return $u;
    }

    private function pedir(array $itens, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/portal/requests', ['itens' => $itens] + $extra);
    }

    private function estoque(): array
    {
        $this->como($this->c['armeiro']);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'COL-1'))->assertCreated();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 50))->assertCreated();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'ENTRADA', 'local_destino_id' => $this->c['localAlfa']->id,
            'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-T-3', 'itens' => [['material_id' => $this->c['arma']->id, 'numero_serie' => 'PST-001']]])->assertCreated();

        return [
            'colete' => ItemIndividual::where('numero_serie', 'COL-1')->value('id'),
            'arma' => ItemIndividual::where('numero_serie', 'PST-001')->value('id'),
            'lote' => (int) DB::table('lotes')->where('codigo', 'L-001')->value('id'),
        ];
    }

    private function saldo(int $lote): int
    {
        return (int) DB::table('saldos_estoque')->where('lote_id', $lote)->where('local_estoque_id', $this->c['localAlfa']->id)->value('quantidade');
    }

    // ---------- 1. Primeiro acesso pelo CPF ----------

    public function test_primeiro_acesso_cria_conta_efetivo_com_senha_provisoria(): void
    {
        $r = $this->postJson('/api/v1/auth/login', ['login' => '529.982.247-25', 'password' => self::CPF])->assertOk()
            ->assertJsonPath('usuario.senha_provisoria', true)->assertJsonPath('usuario.mfa_obrigatorio', false)
            ->assertJsonPath('papeis.0.papel', 'EFETIVO')->assertJsonPath('papeis.0.unidade.id', $this->c['alfa1']->id);
        $this->assertSame(['portal.efetivo'], $r->json('permissoes'));
        $u = Usuario::where('login', self::CPF)->firstOrFail();
        $this->assertSame($this->c['ef']->id, $u->militar_id);
        $this->assertSame($this->c['ef']->nome, $u->nome);
        $this->assertFalse($u->privilegiada());
        $criado = EventoAuditoria::where('acao', 'usuario.criado')->where('recurso_id', $u->id)->firstOrFail();
        $this->assertSame('primeiro_acesso', $criado->dados['origem']);
        $this->assertTrue(EventoAuditoria::where('acao', 'acesso.papel.atribuido')->where('recurso_id', $u->id)->exists());
        // Fluxo normal: troca de senha obrigatória antes de usar o portal.
        $this->getJson('/api/v1/portal/summary')->assertStatus(403)->assertJsonPath('error.code', 'SENHA_PROVISORIA');
        // Segundo login com a mesma senha não cria outra conta.
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', ['login' => self::CPF, 'password' => self::CPF])->assertOk();
        $this->assertSame(1, Usuario::where('militar_id', $this->c['ef']->id)->count());
    }

    public function test_primeiro_acesso_nao_cria_conta_fora_da_regra_e_responde_generico(): void
    {
        $antes = Usuario::count();
        $generica = fn (TestResponse $r) => $r->assertStatus(401)->assertJsonPath('error.code', 'CREDENCIAIS_INVALIDAS');
        // senha diferente do CPF
        $msg = $generica($this->postJson('/api/v1/auth/login', ['login' => self::CPF, 'password' => 'outra-senha']))->json('message');
        // CPF que não é de ninguém
        $this->assertSame($msg, $generica($this->postJson('/api/v1/auth/login', ['login' => '11144477735', 'password' => '11144477735']))->json('message'));
        // militar inativo
        $inativo = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'situacao' => 'INATIVO', 'cpf' => '39053344705']);
        $generica($this->postJson('/api/v1/auth/login', ['login' => $inativo->cpf, 'password' => $inativo->cpf]));
        // militar que já tem conta (com outro login)
        $comConta = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'cpf' => '15350946056']);
        Usuario::factory()->create(['militar_id' => $comConta->id]);
        $generica($this->postJson('/api/v1/auth/login', ['login' => $comConta->cpf, 'password' => $comConta->cpf]));
        $this->assertSame($antes + 1, Usuario::count()); // só a conta preexistente acima
        $this->assertFalse(Usuario::where('login', self::CPF)->exists());
        $this->assertFalse(Usuario::where('login', $inativo->cpf)->exists());
    }

    public function test_primeiro_acesso_respeita_limite_de_tentativas_e_nao_duplica(): void
    {
        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/auth/login', ['login' => self::CPF, 'password' => 'errada-'.$_])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['login' => self::CPF, 'password' => self::CPF])->assertStatus(429);
        $this->assertFalse(Usuario::where('login', self::CPF)->exists());
        // Corrida: a conta já criada por outra requisição (mesmo login) é usada; nunca uma segunda.
        app('cache')->flush();
        $outra = Usuario::factory()->create(['login' => self::CPF, 'password' => self::CPF]);
        $this->postJson('/api/v1/auth/login', ['login' => self::CPF, 'password' => self::CPF])->assertOk();
        $this->assertSame(1, Usuario::where('login', self::CPF)->count());
        $this->assertNull($outra->fresh()->militar_id);
        $this->assertSame(0, Usuario::where('militar_id', $this->c['ef']->id)->count());
    }

    // ---------- 2. Pedidos do efetivo ----------

    public function test_criar_pedido_apto_e_bloqueio_de_letal_validado_no_servidor(): void
    {
        $this->como($this->contaEfetivo($this->c['ef']));
        $this->pedir([['material_id' => $this->c['arma']->id, 'quantidade' => 1], ['material_id' => $this->c['matLote']->id, 'quantidade' => 10]],
            ['retirada_prevista' => now()->addDay()->toDateString(), 'local_retirada_id' => $this->c['localAlfa']->id, 'observacao' => 'Serviço de sábado'])
            ->assertCreated()->assertJsonPath('status', 'PENDENTE')->assertJsonPath('itens.0.material.acautelamento', true);
        $this->assertTrue(EventoAuditoria::where('acao', 'pedido.criado')->exists());
        // armeiro da unidade superior é avisado
        $this->assertTrue(Notificacao::where('usuario_id', $this->c['armeiro']->id)->where('tipo', 'PEDIDO_NOVO')->exists());
        $this->pedir([['material_id' => $this->c['matInd']->id, 'quantidade' => 2]])->assertStatus(422)->assertJsonPath('error.code', 'QUANTIDADE_INVALIDA');
        $this->pedir([['material_id' => $this->c['matInd']->id, 'quantidade' => 1]], ['local_retirada_id' => $this->c['localBravo']->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'LOCAL_INVALIDO');

        $this->como($this->contaEfetivo($this->c['r1']));
        $this->pedir([['material_id' => $this->c['arma']->id, 'quantidade' => 1]])->assertStatus(422)->assertJsonPath('error.code', 'EFETIVO_NAO_APTO_LETAL');
        $cat = $this->getJson('/api/v1/portal/catalog')->assertOk();
        $arma = collect($cat->json('data'))->firstWhere('id', $this->c['arma']->id);
        $this->assertStringContainsString('R1', $arma['bloqueio']);

        $this->como($this->contaEfetivo($this->c['semTipo']));
        $this->pedir([['material_id' => $this->c['municao']->id, 'quantidade' => 5]])->assertStatus(422)->assertJsonPath('error.code', 'EFETIVO_NAO_APTO_LETAL');
        $mun = collect($this->getJson('/api/v1/portal/catalog')->json('data'))->firstWhere('id', $this->c['municao']->id);
        $this->assertStringContainsString('Procure a T.I.', $mun['bloqueio']);
        $this->pedir([['material_id' => $this->c['matLote']->id, 'quantidade' => 5]])->assertCreated(); // EPI pode
    }

    public function test_limite_de_pedidos_abertos(): void
    {
        $this->como($this->contaEfetivo($this->c['ef']));
        $codigos = [];
        foreach (range(1, 3) as $_) {
            $codigos[] = $this->pedir([['material_id' => $this->c['matLote']->id, 'quantidade' => 1]])->assertCreated()->json('codigo');
        }
        $this->pedir([['material_id' => $this->c['matLote']->id, 'quantidade' => 1]])->assertStatus(422)->assertJsonPath('error.code', 'LIMITE_PEDIDOS');
        $this->postJson("/api/v1/portal/requests/{$codigos[0]}/cancel", ['versao' => 1])->assertOk()->assertJsonPath('status', 'CANCELADO');
        $this->pedir([['material_id' => $this->c['matLote']->id, 'quantidade' => 1]])->assertCreated();
        $this->getJson('/api/v1/portal/summary')->assertOk()->assertJsonCount(3, 'pedidos_abertos')->assertJsonPath('limite_abertos', 3);
    }

    public function test_so_o_dono_ve_e_cancela_e_armeiro_fora_do_escopo_nao_ve(): void
    {
        $this->como($this->contaEfetivo($this->c['ef']));
        $cod = $this->pedir([['material_id' => $this->c['matLote']->id, 'quantidade' => 2]])->assertCreated()->json('codigo');

        $this->como($this->contaEfetivo($this->c['r1']));
        $this->getJson("/api/v1/portal/requests/$cod")->assertNotFound();
        $this->postJson("/api/v1/portal/requests/$cod/cancel", ['versao' => 1])->assertNotFound();
        $this->getJson('/api/v1/portal/requests')->assertOk()->assertJsonCount(0, 'data');

        $this->como($this->c['gestorBravo']);
        $this->getJson("/api/v1/material-requests/$cod")->assertNotFound();
        $this->getJson('/api/v1/material-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertNotFound();

        $this->como($this->c['armeiro']);
        $this->getJson('/api/v1/material-requests', ['status' => 'PENDENTE'])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/material-requests?q=PED-'.substr($cod, -6))->assertOk()->assertJsonPath('data.0.codigo', $cod);
        $this->getJson("/api/v1/material-requests/$cod")->assertOk()->assertJsonPath('efetivo_conferencia.cpf_mascarado', '•••.982.247-••')
            ->assertJsonPath('acoes.decidir', true);
        $this->getJson('/api/v1/material-requests/summary')->assertOk()->assertJsonPath('pendentes', 1);
        // armeiro não cancela pelo portal (não é o dono)
        $this->postJson("/api/v1/portal/requests/$cod/cancel", ['versao' => 1])->assertForbidden();
    }

    // ---------- 3. Decisão do armeiro ----------

    public function test_aprovar_com_reducao_e_recusar_exige_motivo(): void
    {
        $efU = $this->contaEfetivo($this->c['ef']);
        $this->como($efU);
        $p = $this->pedir([['material_id' => $this->c['matLote']->id, 'quantidade' => 10], ['material_id' => $this->c['matInd']->id, 'quantidade' => 1]])->assertCreated();
        $cod = $p->json('codigo');
        [$linhaLote, $linhaInd] = [$p->json('itens.0.id'), $p->json('itens.1.id')];
        $outro = $this->pedir([['material_id' => $this->c['matInd']->id, 'quantidade' => 1]])->json('codigo');

        $this->como($this->c['armeiro']);
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1, 'itens' => [['id' => $linhaLote, 'quantidade_aprovada' => 11]]])
            ->assertStatus(422)->assertJsonPath('error.code', 'QUANTIDADE_INVALIDA');
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1, 'observacao' => 'Só 6 disponíveis',
            'itens' => [['id' => $linhaLote, 'quantidade_aprovada' => 6], ['id' => $linhaInd, 'quantidade_aprovada' => 0]]])
            ->assertOk()->assertJsonPath('status', 'APROVADO')->assertJsonPath('itens.0.quantidade_aprovada', 6)->assertJsonPath('itens.1.quantidade_aprovada', 0)
            ->assertJsonPath('observacao_armeiro', 'Só 6 disponíveis');
        $this->assertTrue(Notificacao::where('usuario_id', $efU->id)->where('tipo', 'PEDIDO_APROVADO')->exists());
        $ev = EventoAuditoria::where('acao', 'pedido.aprovado')->firstOrFail();
        $this->assertSame(6, $ev->dados['reducoes'][0]['aprovada']);
        // decidir de novo: transição inválida
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'RECUSAR', 'versao' => 2, 'motivo' => 'Teste de novo'])->assertStatus(409);

        $this->postJson("/api/v1/material-requests/$outro/decision", ['decisao' => 'RECUSAR', 'versao' => 1])->assertStatus(422)->assertJsonPath('error.code', 'MOTIVO_OBRIGATORIO');
        $this->postJson("/api/v1/material-requests/$outro/decision", ['decisao' => 'RECUSAR', 'versao' => 1, 'motivo' => 'Material em manutenção'])
            ->assertOk()->assertJsonPath('status', 'RECUSADO')->assertJsonPath('motivo_recusa', 'Material em manutenção');
        $this->assertTrue(EventoAuditoria::where('acao', 'pedido.recusado')->exists());

        $this->como($efU);
        $this->getJson("/api/v1/portal/requests/$outro")->assertOk()->assertJsonPath('motivo_recusa', 'Material em manutenção')->assertJsonPath('pode_cancelar', false);
        $this->postJson("/api/v1/portal/requests/$outro/cancel", ['versao' => 2])->assertStatus(409);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.link', "/portal/pedidos/$outro");
    }

    public function test_aprovacao_revalida_tipo_do_efetivo(): void
    {
        $this->como($this->contaEfetivo($this->c['ef']));
        $cod = $this->pedir([['material_id' => $this->c['arma']->id, 'quantidade' => 1]])->assertCreated()->json('codigo');
        $this->c['ef']->update(['categoria_efetivo' => 'VIGILANTE']); // mudou depois do pedido
        $this->como($this->c['armeiro']);
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertStatus(422)->assertJsonPath('error.code', 'EFETIVO_NAO_APTO_LETAL');
    }

    // ---------- 4. Entrega ----------

    public function test_entrega_gera_movimentacao_baixa_estoque_e_e_idempotente(): void
    {
        $ids = $this->estoque();
        $efU = $this->contaEfetivo($this->c['ef']);
        $this->como($efU);
        $p = $this->pedir([['material_id' => $this->c['matLote']->id, 'quantidade' => 10], ['material_id' => $this->c['matInd']->id, 'quantidade' => 1]])->assertCreated();
        $cod = $p->json('codigo');
        [$linhaLote, $linhaInd] = [$p->json('itens.0.id'), $p->json('itens.1.id')];

        $this->como($this->c['armeiro']);
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1, 'itens' => [['id' => $linhaLote, 'quantidade_aprovada' => 8]]])->assertOk();
        $corpo = ['versao' => 2, 'local_id' => $this->c['localAlfa']->id, 'previsao_retorno' => now()->addDays(2)->toDateString(),
            'linhas' => [['id' => $linhaLote, 'lotes' => [['lote_id' => $ids['lote'], 'quantidade' => 8]]], ['id' => $linhaInd, 'itens' => [$ids['colete']]]]];
        // acima do aprovado
        $acima = $corpo;
        $acima['linhas'][0]['lotes'][0]['quantidade'] = 9;
        $this->withHeaders($this->chave())->postJson("/api/v1/material-requests/$cod/delivery", $acima)->assertStatus(422)->assertJsonPath('error.code', 'ACIMA_DO_APROVADO');
        $this->flushHeaders()->postJson("/api/v1/material-requests/$cod/delivery", $corpo)->assertStatus(428);

        $chave = $this->chave();
        $r = $this->withHeaders($chave)->postJson("/api/v1/material-requests/$cod/delivery", $corpo)->assertCreated()
            ->assertJsonPath('status', 'ENTREGUE')->assertJsonPath('itens.0.quantidade_entregue', 8)->assertJsonPath('itens.1.quantidade_entregue', 1);
        $mov = Movimentacao::findOrFail($r->json('entrega_movimentacao_id'));
        $this->assertSame('ENTREGA', $mov->tipo);
        $this->assertSame($this->c['ef']->id, $mov->militar_id);
        $this->assertSame('PEDIDO-'.$cod, $mov->documento_referencia);
        $this->assertSame('ENTREGUE', ItemIndividual::find($ids['colete'])->situacao);
        $this->assertSame(42, $this->saldo($ids['lote']));
        $this->assertTrue(EventoAuditoria::where('acao', 'pedido.entregue')->exists());
        $this->assertTrue(EventoAuditoria::where('acao', 'estoque.entrega')->exists());

        // repetição com a mesma chave: mesmo resultado, nada novo
        $this->withHeaders($chave)->postJson("/api/v1/material-requests/$cod/delivery", $corpo)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Movimentacao::where('tipo', 'ENTREGA')->count());
        $this->assertSame(42, $this->saldo($ids['lote']));
        // outra chave: já entregue
        $this->withHeaders($this->chave())->postJson("/api/v1/material-requests/$cod/delivery", $corpo)->assertStatus(409)->assertJsonPath('error.code', 'JA_ENTREGUE');

        // efetivo vê o material com ele
        $this->como($efU);
        $this->getJson('/api/v1/portal/material')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/portal/summary')->assertOk()->assertJsonPath('material.total', 2)->assertJsonCount(0, 'pedidos_abertos');
        $this->postJson("/api/v1/portal/requests/$cod/cancel", ['versao' => 3])->assertStatus(409);
    }

    public function test_arma_de_fogo_e_entregue_por_acautelamento_com_carteira(): void
    {
        $ids = $this->estoque();
        $efU = $this->contaEfetivo($this->c['ef']);
        $this->como($efU);
        $p = $this->pedir([['material_id' => $this->c['arma']->id, 'quantidade' => 1], ['material_id' => $this->c['matLote']->id, 'quantidade' => 2]])->assertCreated();
        $cod = $p->json('codigo');

        $this->como($this->c['armeiro']);
        $this->postJson("/api/v1/material-requests/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertOk();
        $r = $this->withHeaders($this->chave())->postJson("/api/v1/material-requests/$cod/delivery", ['versao' => 2, 'local_id' => $this->c['localAlfa']->id,
            'linhas' => [['id' => $p->json('itens.0.id'), 'itens' => [$ids['arma']]], ['id' => $p->json('itens.1.id'), 'lotes' => [['lote_id' => $ids['lote'], 'quantidade' => 2]]]]])
            ->assertCreated()->assertJsonPath('status', 'ENTREGUE')->assertJsonCount(1, 'acautelamentos');
        $a = Acautelamento::where('codigo', $r->json('acautelamentos.0.codigo'))->firstOrFail();
        $this->assertSame('ATIVO', $a->estado);
        $this->assertSame($efU->id, $a->solicitante_id);
        $this->assertSame($this->c['armeiro']->id, $a->aprovador_id);
        $this->assertNotNull($a->carteira_token);
        $this->assertSame('ACAUTELADO', ItemIndividual::find($ids['arma'])->situacao);
        // sem previsão de retorno: o lote vai como consumo (ENTREGA sem retorno)
        $this->assertSame(48, $this->saldo($ids['lote']));
        $this->como($efU);
        $custodias = collect($this->getJson('/api/v1/portal/material')->json('data'))->pluck('custodia')->all();
        $this->assertSame(['ACAUTELAMENTO'], $custodias);
    }

    public function test_entrega_de_arma_para_quem_nao_pode_recusa_no_servidor(): void
    {
        $ids = $this->estoque();
        $this->como($this->contaEfetivo($this->c['ef']));
        $p = $this->pedir([['material_id' => $this->c['arma']->id, 'quantidade' => 1]])->assertCreated();
        $this->como($this->c['armeiro']);
        $this->postJson("/api/v1/material-requests/{$p->json('codigo')}/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertOk();
        $this->c['ef']->update(['categoria_efetivo' => 'R1']);
        $this->withHeaders($this->chave())->postJson("/api/v1/material-requests/{$p->json('codigo')}/delivery", ['versao' => 2, 'local_id' => $this->c['localAlfa']->id,
            'linhas' => [['id' => $p->json('itens.0.id'), 'itens' => [$ids['arma']]]]])->assertStatus(422)->assertJsonPath('error.code', 'EFETIVO_NAO_APTO_LETAL');
        $this->assertSame('DISPONIVEL', ItemIndividual::find($ids['arma'])->situacao);
        $this->assertSame(0, Acautelamento::count());
        $this->assertSame('APROVADO', PedidoMaterial::first()->status);
    }

    // ---------- 5. Isolamento do papel EFETIVO ----------

    public function test_efetivo_nao_acessa_telas_de_gestao_e_gestao_nao_usa_portal_sem_permissao(): void
    {
        $this->como($this->contaEfetivo($this->c['ef']));
        foreach (['stock/balances', 'stock/individual-items', 'stock/linked', 'movements', 'militares', 'users', 'acautelamentos', 'items',
            'material-requests', 'material-requests/summary', 'audit-events', 'inventories', 'documents', 'locations', 'reports'] as $rota) {
            $this->getJson("/api/v1/$rota")->assertForbidden();
        }
        $this->getJson('/api/v1/alerts')->assertOk();
        $this->getJson('/api/v1/me/profile')->assertOk();

        $this->como($this->c['armeiro']);
        $this->getJson('/api/v1/portal/summary')->assertForbidden();
        $this->postJson('/api/v1/portal/requests', ['itens' => [['material_id' => $this->c['matInd']->id, 'quantidade' => 1]]])->assertForbidden();
    }
}
