<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ImportacaoController;
use App\Models\EventoAuditoria;
use App\Models\Usuario;
use App\Support\Planilha;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PSI-PRB v1.0 — caps. 7.5, 9.3, 10, 11 e 31 (docs/seguranca/PSI-PRB-CONFORMIDADE.md). */
class PsiPrbTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-teste-1234';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    private function trocar(string $nova, string $atual = self::SENHA)
    {
        return $this->postJson('/api/v1/me/password', ['senha_atual' => $atual, 'nova_senha' => $nova, 'nova_senha_confirmation' => $nova]);
    }

    private function entrar(Usuario $u, string $senha = self::SENHA, string $ip = '10.10.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', ['login' => $u->login, 'password' => $senha]);
    }

    // ——— Cap. 10.1 ———

    public function test_regras_de_senha_com_mensagens_em_portugues(): void
    {
        $u = $this->c['gestorAlfa'];
        $u->forceFill(['nome' => 'Joana Barbosa dos Santos', 'login' => '52998224725'])->saveQuietly();
        $this->como($u);
        $casos = [
            'Cur-1a' => 'pelo menos 8 caracteres',
            'sem-maiuscula-123' => 'letra maiúscula',
            'SEM-MINUSCULA-123' => 'letra minúscula',
            'Sem-Numero-Algum' => 'um número',
            'SemEspecial12345' => 'caractere especial',
            'Barbosa-Forte-2026' => 'o seu nome',
            'Xy#529982247259' => 'o seu CPF',
            '@Prb#123!' => 'diferente da senha padrão',
        ];
        foreach ($casos as $senha => $trecho) {
            $msg = implode(' ', $this->trocar($senha)->assertStatus(422)->json('error.fields.nova_senha') ?? []);
            $this->assertStringContainsString($trecho, $msg, "senha $senha");
        }
        $this->trocar('Dos-Anos-Fortes-26')->assertOk(); // partícula "dos" não conta como nome
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auth.senha_trocada', 'resultado' => 'SUCESSO']);
    }

    public function test_nao_repete_as_ultimas_cinco_senhas(): void
    {
        $u = $this->c['gestorAlfa'];
        $this->como($u);
        $senhas = ['Primeira-Forte-01', 'Segunda-Forte-02', 'Terceira-Forte-03', 'Quarta-Forte-04', 'Quinta-Forte-05'];
        $atual = self::SENHA;
        foreach ($senhas as $s) {
            $this->trocar($s, $atual)->assertOk();
            $atual = $s;
        }
        $msg = $this->trocar('Primeira-Forte-01', $atual)->assertStatus(422)->json('error.fields.nova_senha.0');
        $this->assertStringContainsString('últimas 5 senhas', $msg);
        $this->trocar('Sexta-Forte-06', $atual)->assertOk();
        $this->trocar('Primeira-Forte-01', 'Sexta-Forte-06')->assertOk(); // saiu da janela das 5 últimas
        $this->assertSame(7, DB::table('historico_senhas')->where('usuario_id', $u->id)->count() - 1); // + a senha inicial
    }

    public function test_senha_definida_pelo_admin_segue_a_politica_e_padrao_continua_provisoria(): void
    {
        $this->como($this->c['admin']);
        $this->postJson('/api/v1/users', ['nome' => 'Conta Fraca', 'login' => '11144477735', 'password' => 'fraca-demais1'])->assertStatus(422)
            ->assertJsonPath('error.fields.password.0', 'A senha deve ter pelo menos uma letra maiúscula.');
        $alvo = $this->c['operadorAlfa1'];
        $this->putJson("/api/v1/users/{$alvo->id}", ['password' => 'X'.$alvo->login.'-ok'])->assertStatus(422);
        $this->putJson("/api/v1/users/{$alvo->id}", ['senha_padrao' => true])->assertOk(); // padrão de 9 caracteres: só provisória
        $this->assertTrue($alvo->fresh()->senha_provisoria);
    }

    // ——— Cap. 10 (troca a cada 90 dias) ———

    public function test_senha_expirada_exige_troca(): void
    {
        $u = $this->c['gestorAlfa'];
        $u->forceFill(['senha_alterada_em' => now()->subDays(91)])->saveQuietly();
        $this->como($u)->getJson('/api/v1/dashboard')->assertStatus(403)->assertJsonPath('error.code', 'SENHA_EXPIRADA');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('usuario.senha_expirada', true);
        $this->trocar('Renovada-Forte-90')->assertOk()->assertJsonPath('usuario.senha_expirada', false);
        $this->getJson('/api/v1/dashboard')->assertOk();
        $this->assertSame('expirada', EventoAuditoria::where('acao', 'auth.senha_trocada')->latest('id')->first()->dados['motivo']);
        config(['sigarb.senha.validade_dias' => 0]);
        $u->forceFill(['senha_alterada_em' => now()->subYears(2)])->saveQuietly();
        $this->assertFalse($u->fresh()->senhaExpirada());
    }

    // ——— Cap. 10.3 ———

    public function test_dez_falhas_bloqueiam_a_conta_ate_o_desbloqueio(): void
    {
        $u = $this->c['gestorAlfa'];
        for ($i = 0; $i < 9; $i++) {
            $this->entrar($u, 'errada-errada', "10.20.0.$i")->assertStatus(401);
        }
        $this->entrar($u, self::SENHA, '10.20.1.1')->assertOk(); // sucesso zera o contador
        $this->assertSame(0, $u->fresh()->falhas_login);
        auth('web')->logout();
        for ($i = 0; $i < 10; $i++) {
            $this->entrar($u, 'errada-errada', "10.21.0.$i")->assertStatus(401);
        }
        $this->assertNotNull($u->fresh()->bloqueado_em);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auth.conta_bloqueada', 'recurso_id' => $u->id]);
        // Senha certa em conta bloqueada: mesma resposta genérica (sem enumeração nem confirmação de senha).
        $certa = $this->entrar($u, self::SENHA, '10.22.0.1')->assertStatus(401);
        $inexistente = $this->withServerVariables(['REMOTE_ADDR' => '10.22.0.2'])->postJson('/api/v1/auth/login', ['login' => 'ninguem', 'password' => 'x'])->assertStatus(401);
        $this->assertSame($inexistente->json('error.message'), $certa->json('error.message'));
        $this->assertSame('CREDENCIAIS_INVALIDAS', $certa->json('error.code'));
        $this->assertGuest('web');

        $this->como($this->c['gestorBravo'])->postJson("/api/v1/users/{$u->id}/unlock", ['motivo' => 'Pedido do titular'])->assertForbidden();
        $this->como($this->c['admin'])->postJson("/api/v1/users/{$u->id}/unlock", [])->assertStatus(422);
        $this->postJson("/api/v1/users/{$u->id}/unlock", ['motivo' => 'Titular confirmou por telefone'])->assertOk()->assertJsonPath('bloqueado_em', null);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'usuario.desbloqueado', 'recurso_id' => $u->id]);
        auth('web')->logout();
        $this->entrar($u, self::SENHA, '10.23.0.1')->assertOk();
    }

    // ——— Cap. 31 ———

    public function test_falha_de_login_audita_login_mascarado_e_ip_sem_senha(): void
    {
        $this->entrar($this->c['gestorAlfa'], 'Senha-Secreta-XYZ', '10.30.0.7')->assertStatus(401);
        $e = EventoAuditoria::where('acao', 'auth.login')->where('resultado', 'FALHA')->latest('id')->first();
        $this->assertSame('10.30.0.7', $e->ip);
        $this->assertNotSame($this->c['gestorAlfa']->login, $e->dados['login']);
        $this->assertStringContainsString('*', $e->dados['login']);
        $this->assertStringNotContainsString('Senha-Secreta', json_encode($e->dados));
    }

    // ——— Cap. 11 (TOTP opcional; obrigatório só por configuração) ———

    private function ativarMfa(Usuario $u): array
    {
        $this->como($u);
        $segredo = $this->postJson('/api/v1/me/mfa/setup')->assertOk()->assertJsonStructure(['segredo', 'uri'])->json('segredo');
        $this->postJson('/api/v1/me/mfa/confirm', ['codigo' => '000000'])->assertStatus(422);
        $passo = intdiv(time(), 30);
        $codigos = $this->postJson('/api/v1/me/mfa/confirm', ['codigo' => Totp::codigo($segredo, $passo)])->assertOk()
            ->assertJsonPath('perfil.usuario.mfa_ativo', true)->json('codigos_recuperacao');
        auth('web')->logout();
        $this->flushSession();

        return [$segredo, $passo, $codigos];
    }

    public function test_mfa_opcional_cadastro_login_em_duas_etapas_e_recuperacao(): void
    {
        $u = $this->c['admin'];
        $this->como($u)->getJson('/api/v1/dashboard')->assertOk(); // sem MFA cadastrado: ninguém é bloqueado por padrão
        auth('web')->logout();
        [$segredo, $passo, $codigos] = $this->ativarMfa($u);
        $this->assertCount(8, $codigos);
        $this->assertNotSame($segredo, DB::table('usuarios')->where('id', $u->id)->value('mfa_segredo')); // cifrado no banco

        $this->entrar($u)->assertOk()->assertJsonPath('mfa_requerido', true)->assertJsonMissingPath('usuario');
        $this->assertGuest('web');
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/mfa', ['codigo' => Totp::codigo($segredo, $passo)])->assertStatus(422); // código já usado no cadastro
        $this->postJson('/api/v1/auth/mfa', ['codigo' => Totp::codigo($segredo, $passo + 1)])->assertOk()->assertJsonPath('usuario.mfa_ativo', true);
        $this->assertAuthenticatedAs($u);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auth.mfa', 'resultado' => 'SUCESSO']);

        auth('web')->logout();
        $this->flushSession();
        $this->entrar($u)->assertJsonPath('mfa_requerido', true);
        $this->postJson('/api/v1/auth/mfa', ['codigo' => strtolower($codigos[0])])->assertOk();
        auth('web')->logout();
        $this->flushSession();
        $this->entrar($u)->assertJsonPath('mfa_requerido', true);
        $this->postJson('/api/v1/auth/mfa', ['codigo' => $codigos[0]])->assertStatus(422); // recuperação é de uso único
    }

    public function test_sem_etapa_de_senha_nao_ha_segunda_etapa(): void
    {
        $this->postJson('/api/v1/auth/mfa', ['codigo' => '123456'])->assertStatus(401)->assertJsonPath('error.code', 'MFA_EXPIRADO');
    }

    public function test_gestor_redefine_mfa_de_outra_pessoa_com_motivo(): void
    {
        $alvo = $this->c['gestorAlfa'];
        $this->ativarMfa($alvo);
        DB::table('sessions')->insert(['id' => 'viva-mfa', 'user_id' => $alvo->id, 'payload' => '', 'last_activity' => time()]);
        $this->como($this->c['admin'])->postJson("/api/v1/users/{$alvo->id}/mfa/reset", [])->assertStatus(422);
        $this->postJson("/api/v1/users/{$alvo->id}/mfa/reset", ['motivo' => 'Celular perdido'])->assertNoContent();
        $this->assertFalse($alvo->fresh()->mfaAtivo());
        $this->assertDatabaseMissing('sessions', ['id' => 'viva-mfa']);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'usuario.mfa_redefinido', 'recurso_id' => $alvo->id]);
        $this->postJson("/api/v1/users/{$this->c['admin']->id}/mfa/reset", ['motivo' => 'Eu mesmo'])->assertForbidden();
    }

    public function test_mfa_obrigatorio_por_configuracao_bloqueia_ate_cadastrar(): void
    {
        config(['sigarb.mfa.papeis_obrigatorios' => ['ADMIN']]);
        $this->como($this->c['admin'])->getJson('/api/v1/dashboard')->assertStatus(403)->assertJsonPath('error.code', 'MFA_CADASTRO_OBRIGATORIO');
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('usuario.mfa_cadastro_pendente', true);
        $segredo = $this->postJson('/api/v1/me/mfa/setup')->assertOk()->json('segredo');
        $this->postJson('/api/v1/me/mfa/confirm', ['codigo' => Totp::codigo($segredo, intdiv(time(), 30))])->assertOk();
        $this->getJson('/api/v1/dashboard')->assertOk();
        $this->deleteJson('/api/v1/me/mfa', ['codigo' => Totp::codigo($segredo, intdiv(time(), 30) + 1)])->assertStatus(422)->assertJsonPath('error.code', 'MFA_OBRIGATORIO');
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/dashboard')->assertOk(); // papel não listado
    }

    // ——— Cap. 9.3 ———

    public function test_comando_desativa_contas_inativas_ha_mais_de_60_dias(): void
    {
        $velha = $this->c['gestorAlfa'];
        $velha->forceFill(['ultimo_login_em' => now()->subDays(61)])->saveQuietly();
        $nunca = $this->c['gestorBravo'];
        DB::table('usuarios')->where('id', $nunca->id)->update(['created_at' => now()->subDays(70), 'ultimo_login_em' => null]);
        $recente = $this->c['auditor'];
        $recente->forceFill(['ultimo_login_em' => now()->subDays(10)])->saveQuietly();

        $this->artisan('sigarb:contas:desativar-inativas --simular')->assertSuccessful();
        $this->assertTrue($velha->fresh()->ativo);
        $this->artisan('sigarb:contas:desativar-inativas')->assertSuccessful();
        $this->assertFalse($velha->fresh()->ativo);
        $this->assertFalse($nunca->fresh()->ativo);
        $this->assertTrue($recente->fresh()->ativo);
        $this->assertSame(2, EventoAuditoria::where('acao', 'usuario.desativado_inatividade')->count());
    }

    // ——— Cap. 7.5 / Anexo III ———

    public function test_exportacoes_levam_rotulo_e_csv_exportado_reimporta(): void
    {
        $this->como($this->c['admin']);
        $csv = $this->get('/api/v1/data-transfer/fabricantes/export')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF# ".ImportacaoController::ROTULO."\r\n", $csv);
        $modelo = $this->get('/api/v1/data-transfer/fabricantes/template')->assertOk()->streamedContent();
        $this->assertStringNotContainsString(ImportacaoController::ROTULO, $modelo);
        $arq = UploadedFile::fake()->createWithContent('fab.csv', $csv."Fab Reimportado\n");
        $this->post('/api/v1/data-transfer/fabricantes/import', ['arquivo' => $arq, 'simulacao' => 1])->assertOk();
        $xlsx = $this->get('/api/v1/data-transfer/fabricantes/export?formato=xlsx')->assertOk()->getContent();
        $this->assertStringContainsString(ImportacaoController::ROTULO, Planilha::ler($xlsx)[1][0]);
        $this->como($this->c['gestorAlfa']);
        $this->assertStringContainsString(ImportacaoController::ROTULO, $this->get('/api/v1/reports/saldos')->assertOk()->streamedContent());
    }
}
