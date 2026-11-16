<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use App\Models\Militar;
use App\Models\PostoGraduacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dados funcionais da conta (posto, cargo, matrícula, telefone) para quem não é efetivo cadastrado. */
class UsuarioDadosFuncionaisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    public function test_admin_edita_dados_funcionais_de_outra_pessoa_com_motivo_e_auditoria(): void
    {
        $alvo = $this->c['gestorAlfa'];
        $alvo->forceFill(['militar_id' => \App\Models\Militar::factory()->create()->id])->save(); // conta já ligada a um efetivo
        $this->como($this->c['admin']);
        $dados = ['cargo' => 'Auxiliar Administrativo', 'matricula' => '0873', 'telefone' => '(82) 98888-0000'];
        $this->putJson("/api/v1/users/{$alvo->id}", $dados)->assertStatus(422)->assertJsonPath('error.fields.motivo.0', 'Informe o motivo da alteração dos dados funcionais.');
        $this->putJson("/api/v1/users/{$alvo->id}", $dados + ['motivo' => 'Cadastro da conta civil'])->assertOk()
            ->assertJsonPath('cargo', 'Auxiliar Administrativo')->assertJsonPath('matricula', '0873')->assertJsonPath('posto_graduacao', null);
        $e = EventoAuditoria::where('acao', 'usuario.atualizado')->latest('id')->first();
        $this->assertSame('Cadastro da conta civil', $e->dados['motivo']);
        $this->assertSame('0873', $e->dados['depois']['matricula']);
        $this->assertSame('•••0000', $e->dados['depois']['telefone']);
        $this->assertNull($e->dados['antes']['matricula']);
        $this->assertSame($this->c['admin']->id, $e->usuario_id);

        $posto = PostoGraduacao::value('id');
        $this->putJson("/api/v1/users/{$alvo->id}", ['posto_graduacao_id' => $posto, 'motivo' => 'Posto informado'])->assertOk()->assertJsonPath('posto_graduacao.id', $posto);
        $this->putJson("/api/v1/users/{$this->c['gestorBravo']->id}", ['matricula' => '0873', 'motivo' => 'Teste duplicada'])->assertStatus(422)
            ->assertJsonPath('error.fields.matricula.0', 'Esta matrícula já está em outra conta.');
        $this->putJson("/api/v1/users/{$alvo->id}", ['nome' => 'Sem dado funcional'])->assertOk(); // sem campo funcional, sem motivo
    }

    public function test_propria_pessoa_edita_so_os_campos_permitidos(): void
    {
        $u = $this->c['operadorAlfa1'];
        $login = $u->login;
        $this->como($u)->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('posto_graduacao', null)->assertJsonStructure(['postos' => [['id', 'sigla', 'nome']]]);
        $this->putJson('/api/v1/me/profile', ['cargo' => 'Auxiliar Administrativo', 'telefone' => '82 3333-4444', 'matricula' => 'M-55',
            'login' => '52998224725', 'nome' => 'Outro Nome', 'ativo' => false, 'papel' => 'ADMIN', 'atribuicoes' => [['papel' => 'ADMIN']]])
            ->assertOk()->assertJsonPath('cargo', 'Auxiliar Administrativo')->assertJsonPath('matricula', null); // matrícula só o admin
        $u->refresh();
        $this->assertSame($login, $u->login);
        $this->assertNotSame('Outro Nome', $u->nome);
        $this->assertTrue($u->ativo);
        $this->assertSame(['OPERADOR'], $u->atribuicoes()->with('papel')->get()->pluck('papel.codigo')->all());
        $e = EventoAuditoria::where('acao', 'usuario.perfil_atualizado')->latest('id')->first();
        $this->assertSame($u->id, $e->usuario_id);
        $this->assertSame('•••4444', $e->dados['depois']['telefone']); // telefone mascarado na trilha
        $this->assertStringNotContainsString('3333', json_encode($e->dados));
        $this->assertArrayNotHasKey('login', $e->dados['depois']);
        $this->assertArrayNotHasKey('matricula', $e->dados['depois']);
        $this->putJson('/api/v1/me/profile', ['telefone' => 'abc'])->assertStatus(422);
    }

    public function test_dados_do_efetivo_vinculado_so_completos_com_ver_detalhe(): void
    {
        $m = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'matricula' => 'MAT-98765', 'telefone_institucional' => '82 99999-1234']);
        $u = $this->c['gestorAlfa'];
        $u->forceFill(['militar_id' => $m->id])->saveQuietly();
        // Admin sem militares.ver_detalhe: mascarado, sem auditoria de consulta sensível
        $this->como($this->c['admin'])->getJson("/api/v1/users/{$u->id}")->assertOk()
            ->assertJsonPath('militar.matricula', '•••765')->assertJsonPath('militar.telefone', '[informado]');
        $this->getJson('/api/v1/users')->assertOk()->assertDontSee('MAT-98765');
        $this->assertDatabaseMissing('eventos_auditoria', ['acao' => 'militar.consulta_detalhe']);
        // Gestor com ver_detalhe na unidade do efetivo: completo e auditado
        $this->como($u)->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('militar.matricula', 'MAT-98765')->assertJsonPath('militar.telefone', '82 99999-1234');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'militar.consulta_detalhe', 'recurso_id' => $m->id]);
    }

    public function test_perfil_proprio_exige_senha_definitiva(): void
    {
        $u = $this->c['operadorAlfa1'];
        $u->forceFill(['senha_provisoria' => true])->saveQuietly();
        $this->como($u)->putJson('/api/v1/me/profile', ['cargo' => 'X'])->assertStatus(403)->assertJsonPath('error.code', 'SENHA_PROVISORIA');
    }
}
