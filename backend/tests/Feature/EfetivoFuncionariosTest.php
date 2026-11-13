<?php

namespace Tests\Feature;

use App\Models\Militar;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Services\EfetivoFuncionariosService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sincronização do efetivo com o cadastro de funcionários e vínculo das contas.
 * A origem é simulada por uma conexão SQLite em memória com o mesmo esquema (só as colunas lidas).
 */
class EfetivoFuncionariosTest extends TestCase
{
    use RefreshDatabase;

    private const CPF_LARRY = '52998224725';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        config(['database.connections.funcionarios' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('funcionarios');
        $s = Schema::connection('funcionarios');
        foreach (['perimetros', 'bases', 'unidades'] as $t) {
            $s->create($t, fn (Blueprint $b) => [$b->id(), $b->string('nome'), $b->unsignedBigInteger('perimetro_id')->nullable(), $b->unsignedBigInteger('base_id')->nullable()]);
        }
        $s->create('funcionarios', function (Blueprint $b) {
            $b->id();
            $b->string('matricula')->nullable();
            $b->string('nome');
            $b->char('cpf', 11)->nullable();
            $b->string('cargo')->nullable();
            $b->string('categoria')->nullable();
            $b->boolean('ativo');
            $b->string('situacao')->nullable();
            $b->dateTime('atualizado_em')->nullable();
            foreach (['perimetro_id', 'base_id', 'unidade_id', 'setor_id'] as $c) {
                $b->unsignedBigInteger($c)->nullable();
            }
        });
        $f = DB::connection('funcionarios');
        $f->table('perimetros')->insert(['id' => 1, 'nome' => 'Maceió']);
        $f->table('bases')->insert(['id' => 1, 'perimetro_id' => 1, 'nome' => 'Poço']);
        $f->table('unidades')->insert(['id' => 1, 'base_id' => 1, 'nome' => 'Orla']);
        $f->table('funcionarios')->insert([
            ['id' => 264, 'matricula' => '2387', 'nome' => 'Larry Fictício Santos', 'cpf' => self::CPF_LARRY, 'cargo' => 'Analista', 'categoria' => null, 'ativo' => 1, 'situacao' => null, 'perimetro_id' => 1, 'base_id' => 1, 'unidade_id' => 1, 'setor_id' => null],
            ['id' => 300, 'matricula' => null, 'nome' => 'Policial Fictício', 'cpf' => null, 'cargo' => null, 'categoria' => 'pm_ativa', 'ativo' => 1, 'situacao' => 'standby', 'perimetro_id' => 1, 'base_id' => 1, 'unidade_id' => null, 'setor_id' => null],
            ['id' => 301, 'matricula' => '9999', 'nome' => 'Desligado Fictício', 'cpf' => null, 'cargo' => null, 'categoria' => 'vigilante', 'ativo' => 0, 'situacao' => 'desligado', 'perimetro_id' => null, 'base_id' => null, 'unidade_id' => null, 'setor_id' => null],
        ]);
    }

    public function test_sincroniza_mapeia_e_e_idempotente(): void
    {
        $larry = Usuario::factory()->create(['login' => self::CPF_LARRY, 'matricula' => '0873']);
        $semCadastro = Usuario::factory()->create(['login' => '11144477735', 'nome' => 'Admin Externo Fictício', 'cargo' => 'TI']);

        $this->artisan('sigarb:efetivo:sincronizar --simular')->assertExitCode(0);
        $this->assertDatabaseCount('militares', 0);
        $this->assertNull($larry->fresh()->militar_id);

        $this->artisan('sigarb:efetivo:sincronizar')->assertExitCode(0);
        $m = Militar::where('funcionario_id', 264)->firstOrFail();
        $this->assertSame(['2387', self::CPF_LARRY, 'Analista', 'ATIVO', 'MACEIO-POCO-ORLA', 'CIV'],
            [$m->matricula, $m->cpf, $m->funcao, $m->situacao, $m->unidade->sigla, $m->postoGraduacao->sigla]);
        $pm = Militar::where('funcionario_id', 300)->firstOrFail();
        $this->assertSame(['FUNC-300', 'AFASTADO', 'PM_ATIVA', 'MACEIO-POCO'], [$pm->matricula, $pm->situacao, $pm->categoria_efetivo, $pm->unidade->sigla]);
        $des = Militar::where('funcionario_id', 301)->firstOrFail();
        $this->assertSame(['INATIVO', 'PRB'], [$des->situacao, $des->unidade->sigla]);
        // Contas: Larry pelo CPF; a outra ganha efetivo local
        $this->assertSame($m->id, $larry->fresh()->militar_id);
        $local = Militar::find($semCadastro->fresh()->militar_id);
        $this->assertNull($local->funcionario_id);
        $this->assertSame(EfetivoFuncionariosService::OBS_LOCAL, $local->observacoes_administrativas);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'efetivo.sincronizacao']);

        $total = Militar::count();
        // Campo local preservado; segunda execução sem mudanças
        $m->update(['nome_guerra' => 'LARRY', 'telefone_institucional' => '82 99999-0000']);
        $this->artisan('sigarb:efetivo:sincronizar')->expectsOutputToContain('sem_mudanca_na_origem=1')->assertExitCode(0);
        $this->artisan('sigarb:efetivo:sincronizar --forcar')->expectsOutputToContain('criados=0 atualizados=0 sem_mudanca=3 inativados=0')->assertExitCode(0);
        $this->assertSame('LARRY', $m->fresh()->nome_guerra);
        $this->assertSame($total, Militar::count());

        // Some da origem → INATIVO, nunca apagado
        DB::connection('funcionarios')->table('funcionarios')->where('id', 300)->delete();
        $this->artisan('sigarb:efetivo:sincronizar')->assertExitCode(0);
        $this->assertSame('INATIVO', $pm->fresh()->situacao);
    }

    public function test_desligado_na_origem_desativa_a_conta_e_religado_nao_reativa(): void
    {
        $larry = Usuario::factory()->create(['login' => self::CPF_LARRY]);
        $local = Usuario::factory()->create(['login' => '11144477735']);
        $this->artisan('sigarb:efetivo:sincronizar')->assertExitCode(0);
        $f = DB::connection('funcionarios')->table('funcionarios')->where('id', 264);
        $f->update(['ativo' => 0, 'situacao' => 'desligado', 'atualizado_em' => now()]);
        $this->artisan('sigarb:efetivo:sincronizar')->assertExitCode(0);
        $this->assertFalse($larry->fresh()->ativo);
        $this->assertNotNull($larry->fresh()->desativado_em);
        $this->assertTrue($local->fresh()->ativo); // efetivo local não é afetado
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'usuario.desativado_pela_origem', 'recurso_id' => $larry->id]);
        $f->update(['ativo' => 1, 'situacao' => null, 'atualizado_em' => now()->addMinute()]);
        $this->artisan('sigarb:efetivo:sincronizar')->assertExitCode(0);
        $this->assertFalse($larry->fresh()->ativo);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'usuario.reativacao_pendente_origem', 'recurso_id' => $larry->id]);
    }

    public function test_sem_conexao_configurada_pula(): void
    {
        config(['database.connections.funcionarios.database' => null]);
        $this->artisan('sigarb:efetivo:sincronizar')->expectsOutputToContain('não configurada')->assertExitCode(0);
    }

    public function test_conta_exige_efetivo_com_mesmo_cpf(): void
    {
        $this->artisan('sigarb:efetivo:sincronizar')->assertExitCode(0);
        $larry = Militar::where('funcionario_id', 264)->first();
        $admin = $this->como($this->c['admin']);
        $admin->postJson('/api/v1/users', ['nome' => 'Sem Efetivo', 'login' => '11144477735'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['militar_id']]]);
        $admin->postJson('/api/v1/users', ['nome' => 'CPF Diferente', 'login' => '11144477735', 'militar_id' => $larry->id])
            ->assertStatus(422)->assertJsonPath('error.fields.militar_id.0', 'O CPF do efetivo escolhido é diferente do CPF de acesso.');
        $admin->postJson('/api/v1/users', ['nome' => 'Larry', 'login' => self::CPF_LARRY, 'militar_id' => $larry->id])->assertCreated();
        // Busca do efetivo por CPF e indicação de origem/acesso
        // CPF só é pesquisável por quem vê o detalhe (gestor da raiz PRB), como a matrícula.
        $gestor = $this->como($this->usuarioCom('GESTOR', Unidade::where('sigla', 'PRB')->value('id')));
        $gestor->getJson('/api/v1/militares?q='.self::CPF_LARRY)->assertOk()->assertJsonPath('data.0.id', $larry->id)
            ->assertJsonPath('data.0.origem', 'FUNCIONARIOS')->assertJsonPath('data.0.tem_acesso', true);
        $gestor->getJson('/api/v1/militares?sem_conta=1&per_page=100')->assertOk()->assertJsonMissing(['id' => $larry->id, 'nome' => $larry->nome]);
    }

    public function test_vincular_usuarios_simulado_nao_grava(): void
    {
        $u = Usuario::factory()->create(['login' => '11144477735']);
        $this->artisan('sigarb:efetivo:vincular-usuarios --simular')->expectsOutputToContain('[simulação] + conta')->assertExitCode(0);
        $this->assertNull($u->fresh()->militar_id);
        $this->artisan('sigarb:efetivo:vincular-usuarios')->assertExitCode(0);
        $this->assertNotNull($u->fresh()->militar_id);
        $this->assertSame(0, Usuario::whereNull('militar_id')->count());
    }
}
