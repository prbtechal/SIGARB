<?php

namespace Tests\Feature;

use App\Models\Militar;
use App\Models\Unidade;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsuarioEdicaoEstruturaPrbTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_edita_conta_e_login_passa_a_ser_cpf(): void
    {
        $this->cenario();
        $alvo = Usuario::factory()->create(['login' => 'antigo.login']);
        $this->como($this->c['admin'])->putJson("/api/v1/users/{$alvo->id}", ['nome' => 'Nome Novo', 'login' => '987.654.321-00', 'email' => 'novo@exemplo.test'])
            ->assertOk()->assertJsonPath('login', '98765432100')->assertJsonPath('nome', 'Nome Novo');
        Usuario::factory()->create(['login' => '24681357928']);
        $this->putJson("/api/v1/users/{$alvo->id}", ['login' => '24681357928'])->assertStatus(422); // CPF já usado
        $this->putJson("/api/v1/users/{$alvo->id}", ['login' => '12345678900'])->assertStatus(422); // dígito verificador
        $this->putJson("/api/v1/users/{$alvo->id}", ['login' => '11111111111'])->assertStatus(422);
        $this->como($this->c['gestorAlfa'])->putJson("/api/v1/users/{$alvo->id}", ['nome' => 'Outro'])->assertForbidden();
    }

    public function test_conta_nova_usa_senha_padrao_e_troca_no_primeiro_acesso(): void
    {
        $this->cenario();
        $this->como($this->c['admin'])->postJson('/api/v1/users', ['nome' => 'Conta Nova', 'login' => '13579246828'])->assertCreated();
        auth('web')->logout();
        // Senha inicial = o próprio CPF (decisão 2026-11-11, exceção E-03 da PSI-PRB)
        $this->postJson('/api/v1/auth/login', ['login' => '135.792.468-28', 'password' => '13579246828'])->assertOk()->assertJsonPath('usuario.senha_provisoria', true);
        // Bloqueado até trocar
        $this->getJson('/api/v1/dashboard')->assertStatus(403)->assertJsonPath('error.code', 'SENHA_PROVISORIA');
        $this->getJson('/api/v1/me')->assertOk();
        $this->postJson('/api/v1/me/password', ['senha_atual' => 'errada', 'nova_senha' => 'Minha-senha-123', 'nova_senha_confirmation' => 'Minha-senha-123'])->assertStatus(422);
        $this->postJson('/api/v1/me/password', ['senha_atual' => '13579246828', 'nova_senha' => 'curta1', 'nova_senha_confirmation' => 'curta1'])->assertStatus(422);
        // Repetir o CPF (ou contê-lo) é recusado
        $this->postJson('/api/v1/me/password', ['senha_atual' => '13579246828', 'nova_senha' => 'Ab#13579246828', 'nova_senha_confirmation' => 'Ab#13579246828'])
            ->assertStatus(422)->assertJsonPath('error.fields.nova_senha.0', 'A senha não pode conter o seu CPF (login).');
        $this->postJson('/api/v1/me/password', ['senha_atual' => '13579246828', 'nova_senha' => '13579246828', 'nova_senha_confirmation' => '13579246828'])->assertStatus(422);
        $this->postJson('/api/v1/me/password', ['senha_atual' => '13579246828', 'nova_senha' => 'Minha-senha-123', 'nova_senha_confirmation' => 'Minha-senha-123'])
            ->assertOk()->assertJsonPath('usuario.senha_provisoria', false);
        $this->getJson('/api/v1/dashboard')->assertOk();
        // Redefinir para a padrão volta a exigir troca
        $u = Usuario::where('login', '13579246828')->first();
        $this->como($this->c['admin'])->putJson("/api/v1/users/{$u->id}", ['senha_padrao' => true])->assertOk();
        $this->assertTrue($u->fresh()->senha_provisoria);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('13579246828', $u->fresh()->password));
        // Conta com papel ADMIN redefinida: senha padrão de config (reserva), não o CPF
        $adm = $this->usuarioCom('ADMIN', null);
        $this->putJson("/api/v1/users/{$adm->id}", ['senha_padrao' => true])->assertOk();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(config('sigarb.senha.padrao'), $adm->fresh()->password));
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auth.senha_trocada', 'resultado' => 'SUCESSO']);
    }

    public function test_conta_com_senha_cpf_que_recebe_papel_privilegiado_passa_a_senha_padrao(): void
    {
        $this->cenario();
        $this->como($this->c['admin'])->postJson('/api/v1/users', ['nome' => 'Futuro Gestor TI', 'login' => '52998224725'])->assertCreated();
        $u = Usuario::where('login', '52998224725')->first();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('52998224725', $u->password));
        $this->postJson("/api/v1/users/{$u->id}/roles", ['papel' => 'OPERADOR', 'unidade_id' => $this->c['alfa']->id])->assertCreated();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('52998224725', $u->fresh()->password)); // papel comum: segue o CPF
        $this->postJson("/api/v1/users/{$u->id}/roles", ['papel' => 'ADMIN'])->assertCreated();
        $u->refresh();
        $this->assertTrue($u->senha_provisoria);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(config('sigarb.senha.padrao'), $u->password));
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'usuario.senha_provisoria_trocada', 'recurso_id' => $u->id]);
        // Redefinição de conta privilegiada (por permissão efetiva) também usa a padrão
        $this->putJson("/api/v1/users/{$u->id}", ['senha_padrao' => true])->assertOk();
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('52998224725', $u->fresh()->password));
    }

    public function test_so_quem_classifica_altera_tipo_de_efetivo(): void
    {
        $this->cenario();
        $m = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'categoria_efetivo' => null]);
        // Gestor edita o militar, mas o tipo não faz parte do cadastro comum
        $this->como($this->c['gestorAlfa'])->putJson("/api/v1/militares/{$m->id}", ['categoria_efetivo' => 'PM_ATIVA'])->assertOk();
        $this->assertNull($m->fresh()->categoria_efetivo);
        $this->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'PM_ATIVA', 'motivo' => 'Conferido'])->assertForbidden();
        $this->como($this->c['admin'])->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'PM_ATIVA'])->assertStatus(422); // sem motivo
        $this->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'PM_ATIVA', 'motivo' => 'Conferido no RH'])->assertOk()->assertJsonPath('categoria_efetivo', 'PM_ATIVA');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'militar.categoria_alterada']);
    }

    public function test_matriz_de_permissoes_editavel_pelo_admin_com_trava(): void
    {
        $this->cenario();
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/permissions')->assertForbidden();
        $this->como($this->c['admin'])->getJson('/api/v1/permissions')->assertOk()->assertJsonFragment(['codigo' => 'militares.classificar']);
        $this->getJson('/api/v1/militares')->assertOk();
        // Dá ao GESTOR a permissão de classificar e retira de volta
        $this->putJson('/api/v1/roles/GESTOR/permissions/militares.classificar', ['concedida' => true, 'motivo' => 'Teste de matriz'])->assertOk();
        $m = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);
        $this->como($this->c['gestorAlfa'])->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'RR', 'motivo' => 'Conferido'])->assertOk();
        $this->como($this->c['admin'])->putJson('/api/v1/roles/GESTOR/permissions/militares.classificar', ['concedida' => false, 'motivo' => 'Teste de matriz'])->assertOk();
        $this->como($this->c['gestorAlfa'])->putJson("/api/v1/militares/{$m->id}/categoria", ['categoria_efetivo' => 'R1', 'motivo' => 'Conferido'])->assertForbidden();
        // Trava: admin não perde o que permite desfazer
        $this->como($this->c['admin'])->putJson('/api/v1/roles/ADMIN/permissions/sistema.configurar', ['concedida' => false, 'motivo' => 'Teste de trava'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PERMISSAO_TRAVADA');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'permissao.alterada']);
    }

    public function test_estrutura_prb_criada_pela_migracao(): void
    {
        $orla = Unidade::where('sigla', 'MACEIO-POCO-ORLA')->firstOrFail();
        $poco = Unidade::where('sigla', 'MACEIO-POCO')->firstOrFail();
        $maceio = Unidade::where('sigla', 'MACEIO')->firstOrFail();
        $this->assertSame('PRB', $maceio->organizacao->sigla);
        $this->assertTrue($maceio->contem($orla) && $poco->contem($orla));
        foreach (['ARAPIRACA', 'PARIPUEIRA', 'RIO-LARGO', 'MARECHAL-DEODORO-FRANCES', 'MACEIO-BENEDITO'] as $s) {
            $this->assertTrue(Unidade::where('sigla', $s)->exists(), $s);
        }
    }
}
