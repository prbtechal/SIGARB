<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RF-001, RF-002, RF-004, RNF-02, UC-01. */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    public function test_login_valido_cria_sessao_e_audita(): void
    {
        $u = $this->c['gestorAlfa'];
        $this->postJson('/api/v1/auth/login', ['login' => $u->login, 'password' => 'senha-teste-1234'])
            ->assertOk()->assertJsonPath('usuario.login', $u->login)->assertJsonPath('ambiente', 'DEMONSTRACAO')
            ->assertJsonFragment(['papel' => 'GESTOR']);
        $this->assertAuthenticatedAs($u);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auth.login', 'resultado' => 'SUCESSO', 'usuario_id' => $u->id]);
        $this->assertNotNull($u->fresh()->ultimo_login_em);
    }

    public function test_senha_errada_e_usuario_inexistente_tem_mesma_resposta_generica(): void
    {
        $a = $this->postJson('/api/v1/auth/login', ['login' => $this->c['gestorAlfa']->login, 'password' => 'errada-errada'])->assertStatus(401);
        $b = $this->postJson('/api/v1/auth/login', ['login' => 'ninguem', 'password' => 'errada-errada'])->assertStatus(401);
        $this->assertSame($a->json('error.message'), $b->json('error.message'));
        $this->assertSame('CREDENCIAIS_INVALIDAS', $a->json('error.code'));
        $this->assertGuest();
        $this->assertSame(2, EventoAuditoria::where('acao', 'auth.login')->where('resultado', 'FALHA')->count());
    }

    public function test_auditoria_nunca_grava_senha(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'x', 'password' => 'SEGREDO-UNICO-123']);
        $this->assertFalse(EventoAuditoria::all()->contains(fn ($e) => str_contains(json_encode($e->dados), 'SEGREDO-UNICO-123')));
    }

    public function test_conta_inativa_nao_autentica(): void
    {
        $u = $this->c['operadorAlfa1'];
        $u->update(['ativo' => false]);
        $this->postJson('/api/v1/auth/login', ['login' => $u->login, 'password' => 'senha-teste-1234'])->assertStatus(401);
    }

    public function test_limite_de_tentativas_bloqueia_com_429(): void
    {
        $login = $this->c['gestorAlfa']->login;
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'errada-errada'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'senha-teste-1234'])
            ->assertStatus(429)->assertJsonPath('error.code', 'MUITAS_TENTATIVAS');
    }

    public function test_ataque_distribuido_contra_uma_conta_e_bloqueado(): void
    {
        $login = $this->c['gestorAlfa']->login;
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'errada-errada'])->assertStatus(401);
        }
        // atacante (IP com falhas) continua bloqueado, mesmo acertando
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'errada-errada'])->assertStatus(429);
        // senha errada de IP novo conta como falha, mas o dono legítimo (IP limpo, senha certa) não fica trancado
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->postJson('/api/v1/auth/login', ['login' => $login, 'password' => 'senha-teste-1234'])->assertOk();
    }

    public function test_login_com_caracteres_nao_ascii_e_rejeitado(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'gestör', 'password' => 'x'])->assertStatus(422);
    }

    public function test_varredura_de_contas_a_partir_de_um_ip_e_bloqueada(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => "conta$i", 'password' => 'errada-errada'])->assertStatus(401);
        }
        $this->postJson('/api/v1/auth/login', ['login' => $this->c['gestorBravo']->login, 'password' => 'senha-teste-1234'])->assertStatus(429);
    }

    public function test_rotas_protegidas_exigem_autenticacao(): void
    {
        foreach (['/api/v1/me', '/api/v1/militares', '/api/v1/stock/balances', '/api/v1/audit-events', '/api/v1/users'] as $rota) {
            $this->getJson($rota)->assertStatus(401)->assertJsonPath('error.code', 'NAO_AUTENTICADO');
        }
    }

    public function test_logout_audita_e_encerra(): void
    {
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertGuest('web');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auth.logout']);
    }

    public function test_limite_de_sessoes_simultaneas_revoga_as_mais_antigas(): void
    {
        $u = $this->c['gestorAlfa'];
        foreach (range(1, 4) as $i) {
            DB::table('sessions')->insert(['id' => "antiga$i", 'user_id' => $u->id, 'payload' => '', 'last_activity' => time() - 1000 + $i]);
        }
        $this->postJson('/api/v1/auth/login', ['login' => $u->login, 'password' => 'senha-teste-1234'])->assertOk();
        // máx. 3 simultâneas → mantém as 2 anteriores mais recentes + a atual
        $ids = DB::table('sessions')->where('user_id', $u->id)->pluck('id');
        $this->assertCount(3, $ids);
        $this->assertTrue($ids->contains('antiga3') && $ids->contains('antiga4'));
        $this->assertFalse($ids->contains('antiga1') || $ids->contains('antiga2'));
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auth.sessao.revogada_limite']);
    }

    public function test_desativar_conta_revoga_sessoes_e_bloqueia_sessao_viva(): void
    {
        $alvo = $this->c['operadorAlfa1'];
        DB::table('sessions')->insert(['id' => 'viva', 'user_id' => $alvo->id, 'payload' => '', 'last_activity' => time()]);
        $this->como($this->c['admin'])->putJson("/api/v1/users/{$alvo->id}", ['ativo' => false])->assertOk()->assertJsonPath('ativo', false);
        $this->assertDatabaseMissing('sessions', ['id' => 'viva']);
        $this->como($alvo->fresh())->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'CONTA_INATIVA');
    }

    public function test_usuario_lista_e_revoga_propria_sessao_sem_expor_id_real(): void
    {
        $u = $this->c['gestorAlfa'];
        DB::table('sessions')->insert(['id' => 'outra-sessao', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
        $res = $this->como($u)->getJson('/api/v1/me/sessions')->assertOk();
        $this->assertStringNotContainsString('outra-sessao', $res->getContent());
        $ref = substr(hash('sha256', 'outra-sessao'), 0, 16);
        $this->deleteJson("/api/v1/me/sessions/$ref")->assertNoContent();
        $this->assertDatabaseMissing('sessions', ['id' => 'outra-sessao']);
    }

    public function test_admin_revoga_sessoes_de_outro_mas_nao_as_proprias_por_essa_rota(): void
    {
        $alvo = $this->c['gestorBravo'];
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $alvo->id, 'payload' => '', 'last_activity' => time()]);
        $this->como($this->c['admin'])->postJson("/api/v1/users/{$alvo->id}/sessions/revoke")->assertOk()->assertJsonPath('revogadas', 1);
        $this->postJson("/api/v1/users/{$this->c['admin']->id}/sessions/revoke")->assertForbidden();
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/users/{$alvo->id}/sessions/revoke")->assertForbidden();
    }
}
