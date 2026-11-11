<?php

namespace Tests\Feature;

use App\Models\Acautelamento;
use App\Models\ItemIndividual;
use App\Models\EventoAuditoria;
use App\Models\Militar;
use App\Models\Papel;
use App\Models\Usuario;
use App\Services\AutorizacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CP-14 — P-02, P-06 e P-07 decididas pelo responsável; Armeiro com baixa, saída, transferência e aprovações;
 * delegação com prazo; validade da carteira; importação de unidades e locais.
 */
class Cp14PoliticasFinaisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]); // nada depende mais do portão provisório
        $this->cenario();
        $this->c['armeiro'] = $this->usuarioCom('ARMEIRO', $this->c['alfa']->id);
        $this->c['armeiro2'] = $this->usuarioCom('ARMEIRO', $this->c['alfa']->id);
    }

    private function item(string $id): int
    {
        $this->como($this->c['armeiro'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], $id))->assertCreated();

        return ItemIndividual::where('numero_serie', $id)->value('id');
    }

    public function test_nenhuma_operacao_fica_bloqueada(): void
    {
        $ops = collect($this->como($this->c['armeiro'])->getJson('/api/v1/operations')->json('data'));
        $this->assertSame([], $ops->where('estado', '!=', 'HABILITADA')->pluck('tipo')->all());
    }

    public function test_armeiro_faz_baixa_saida_e_transferencia_recebida_pelo_destino(): void
    {
        // Itens criados antes: item() define o Idempotency-Key e não pode rodar dentro dos argumentos de outro POST.
        [$b, $sa, $t] = [$this->item('B-1'), $this->item('S-1'), $this->item('T-1')];
        $base = fn ($id) => ['local_origem_id' => $this->c['localAlfa']->id, 'justificativa' => 'Operação de teste', 'documento_referencia' => 'DOC-14', 'itens' => [['item_individual_id' => $id]]];
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'BAIXA'] + $base($b))->assertCreated();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'SAIDA', 'destinacao' => 'Doação fictícia'] + $base($sa))->assertCreated();
        $this->assertSame(['BAIXADO', 'FORA_DO_ESTOQUE'], [ItemIndividual::where('numero_serie', 'B-1')->value('situacao'), ItemIndividual::where('numero_serie', 'S-1')->value('situacao')]);

        // P-02: transferência para a subunidade; quem recebe precisa de permissão no destino
        $env = $this->withHeaders($this->chave())->postJson('/api/v1/transfers', ['local_destino_id' => $this->c['localAlfa1']->id] + $base($t))->assertCreated()->json('id');
        $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'RECEBIMENTO', 'vinculo_id' => $env])->assertForbidden();
        $this->como($this->c['armeiro'])->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'RECEBIMENTO', 'vinculo_id' => $env])->assertCreated();
        $this->assertSame($this->c['localAlfa1']->id, ItemIndividual::where('numero_serie', 'T-1')->value('local_estoque_id'));
    }

    public function test_armeiro_pede_e_aprova_o_proprio_acautelamento_e_tudo_fica_auditado(): void
    {
        $item = $this->item('ARM-14');
        $efetivo = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);
        $cod = $this->postJson('/api/v1/acautelamentos/requests', ['militar_id' => $efetivo->id, 'item_individual_id' => $item, 'justificativa' => 'Serviço operacional'])->assertCreated()->json('codigo');
        $this->getJson("/api/v1/acautelamentos/$cod")->assertJsonPath('acoes.decidir', true);
        $this->assertContains('ACAUTELAMENTO_PENDENTE', collect($this->getJson('/api/v1/alerts')->json('data'))->pluck('tipo')->all());
        $this->postJson("/api/v1/acautelamentos/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertOk()->assertJsonPath('estado', 'APROVADO');
        $this->postJson("/api/v1/acautelamentos/$cod/delivery", ['versao' => 2])->assertOk()->assertJsonPath('estado', 'ATIVO');

        $arm = $this->c['armeiro'];
        foreach (['acautelamento.aprovado', 'acautelamento.entregue'] as $acao) {
            $d = EventoAuditoria::where('acao', $acao)->latest('id')->firstOrFail()->dados;
            $this->assertTrue($d['autoaprovacao'], $acao);
            $this->assertSame([$arm->id, $arm->id, $arm->id], [$d['solicitante']['id'], $d['aprovador']['id'], $d['operado_por']['id']]);
            $this->assertSame([$efetivo->id, 'ARM-14', 1], [$d['militar']['id'], $d['item']['numero_serie'], $d['quantidade']]);
            $this->assertNotEmpty($d['material']['nome']);
            $this->assertNotEmpty($d['decidido_em']);
        }

        // Gestor também se autoaprova; papel fora da lista não.
        $item2 = $this->item('ARM-15'); // item() opera como Armeiro: criar antes de trocar de usuário
        $this->como($this->c['gestorAlfa']);
        $cod2 = $this->postJson('/api/v1/acautelamentos/requests', ['militar_id' => $efetivo->id, 'item_individual_id' => $item2, 'justificativa' => 'Serviço operacional'])->assertCreated()->json('codigo');
        config(['sigarb.politicas.autoaprovacao_papeis' => ['ARMEIRO']]);
        $this->postJson("/api/v1/acautelamentos/$cod2/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertForbidden()->assertJsonPath('error.code', 'SEGREGACAO_FUNCOES');
        config(['sigarb.politicas.autoaprovacao_papeis' => ['ARMEIRO', 'GESTOR', 'APROVADOR']]);
        $this->getJson("/api/v1/acautelamentos/$cod2")->assertJsonPath('acoes.decidir', true);
        $this->como($arm)->postJson("/api/v1/acautelamentos/$cod2/decision", ['decisao' => 'REJEITAR', 'versao' => 1])->assertStatus(422); // rejeição exige justificativa
        $this->postJson("/api/v1/acautelamentos/$cod2/decision", ['decisao' => 'REJEITAR', 'justificativa' => 'Sem necessidade', 'versao' => 1])->assertOk()->assertJsonPath('estado', 'REJEITADO');
        $this->assertFalse(EventoAuditoria::where('acao', 'acautelamento.rejeitado')->latest('id')->firstOrFail()->dados['autoaprovacao']);
    }

    public function test_carteira_perde_validade_com_inspecao_vencida(): void
    {
        $item = $this->item('ARM-16');
        $efetivo = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);
        $cod = $this->postJson('/api/v1/acautelamentos/requests', ['militar_id' => $efetivo->id, 'item_individual_id' => $item, 'justificativa' => 'Serviço operacional'])->json('codigo');
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/acautelamentos/$cod/decision", ['decisao' => 'APROVAR', 'versao' => 1])->assertOk();
        $this->como($this->c['armeiro'])->postJson("/api/v1/acautelamentos/$cod/delivery", ['versao' => 2])->assertOk();
        $cart = $this->getJson("/api/v1/acautelamentos/$cod/carteira")->assertOk()->assertJsonPath('valida_ate', now()->addDays(365)->toDateString())->json();
        $this->getJson('/api/v1/carteiras/'.$cart['token'])->assertJsonPath('valida', true);

        Acautelamento::where('codigo', $cod)->update(['proxima_inspecao' => now()->subDay()->toDateString()]);
        $this->getJson('/api/v1/carteiras/'.$cart['token'])->assertJsonPath('valida', false)->assertJsonPath('motivo', 'INSPECAO_VENCIDA');
    }

    public function test_delegacao_com_prazo_vale_ate_vencer_e_pode_ser_renovada(): void
    {
        $sub = Usuario::factory()->create();
        $url = "/api/v1/users/{$sub->id}/roles";
        $this->como($this->c['admin'])->postJson($url, ['papel' => 'APROVADOR', 'unidade_id' => $this->c['alfa']->id, 'expira_em' => now()->subDay()->toDateString()])->assertStatus(422);
        $this->postJson($url, ['papel' => 'APROVADOR', 'unidade_id' => $this->c['alfa']->id, 'expira_em' => now()->addDays(5)->toDateString()])->assertCreated()
            ->assertJsonPath('atribuicoes.0.vencida', false);
        $this->assertTrue(app(AutorizacaoService::class)->pode($sub, 'acautelamento.aprovar', $this->c['alfa']->id));

        DB::table('atribuicoes_papel')->where('usuario_id', $sub->id)->update(['expira_em' => now()->subMinute()]);
        app(AutorizacaoService::class)->limpar();
        $this->assertFalse(app(AutorizacaoService::class)->pode($sub, 'acautelamento.aprovar', $this->c['alfa']->id));
        $this->postJson($url, ['papel' => 'APROVADOR', 'unidade_id' => $this->c['alfa']->id, 'expira_em' => now()->addDays(10)->toDateString()])->assertCreated();
        $this->assertSame(1, DB::table('atribuicoes_papel')->where('usuario_id', $sub->id)->where('papel_id', Papel::where('codigo', 'APROVADOR')->value('id'))->count());
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'acesso.papel.atribuido', 'recurso_id' => $sub->id]);
    }

    public function test_importacao_de_unidades_e_locais_monta_a_hierarquia(): void
    {
        $org = DB::table('organizacoes')->where('id', $this->c['alfa']->organizacao_id)->value('sigla');
        $csv = fn ($c) => UploadedFile::fake()->createWithContent('p.csv', $c);
        $this->como($this->c['admin'])->post('/api/v1/data-transfer/unidades/import', ['simulacao' => 0, 'arquivo' => $csv(
            "organizacao_sigla;sigla;nome;tipo;unidade_superior_sigla\n$org;9CIA;9ª Companhia;UNIDADE;\n$org;1PEL-9CIA;1º Pelotão da 9ª Cia;SUBUNIDADE;9CIA\n")])
            ->assertOk()->assertJsonPath('gravadas', 2)->assertJsonPath('erros', []);
        $sup = DB::table('unidades')->where('sigla', '9CIA')->value('id');
        $this->assertSame($sup, DB::table('unidades')->where('sigla', '1PEL-9CIA')->value('unidade_superior_id'));
        $this->post('/api/v1/data-transfer/locais/import', ['simulacao' => 0, 'arquivo' => $csv("unidade_sigla;codigo;nome;tipo\n9CIA;RES-9CIA;Reserva da 9ª Cia;RESERVA\n")])
            ->assertJsonPath('gravadas', 1);
        // Armeiro não cadastra estrutura
        $this->como($this->c['armeiro'])->post('/api/v1/data-transfer/locais/import', ['simulacao' => 1, 'arquivo' => $csv("unidade_sigla;codigo;nome;tipo\nT-ALFA;RES-X;Reserva X;RESERVA\n")])
            ->assertOk()->assertJsonPath('validas', 0);
        $this->get('/api/v1/data-transfer/locais/export')->assertOk();
    }
}
