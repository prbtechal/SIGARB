<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pdo\Mysql;
use Tests\TestCase;

/**
 * GET /api/v1/central-suporte (Central de Suporte PRB, Central-Suporte/docs/INTEGRACAO.md §3): só o CPF da conta logada
 * (usuarios.login); desligada sem SUPORTE_DB_DATABASE; banco/view ausente = resposta vazia 200; links montados a partir
 * de SUPORTE_URL. As "views" viram tabelas no banco de teste, numa conexão `suporte` SEPARADA (o DDL não interfere na
 * transação do RefreshDatabase da conexão padrão).
 */
class CentralSuporteTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://192.168.200.8/suporte';

    private const CPF = '52998224725';

    private const OUTRO_CPF = '11144477735';

    private const ULID = '01JDCENTRALARSENAL00000001';

    private const TABELAS = ['v_chamados_solicitante', 'v_notificacoes_solicitante'];

    private Usuario $eu;

    private Usuario $outro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        $this->eu = $this->usuarioCom('OPERADOR', $this->c['alfa1']->id);
        $this->eu->forceFill(['login' => self::CPF])->save();
        $this->outro = $this->usuarioCom('OPERADOR', $this->c['alfa1']->id);
        $this->outro->forceFill(['login' => self::OUTRO_CPF])->save();
        Cache::flush();
        config(['central_suporte.url' => self::URL, 'central_suporte.banco' => 'suporte']);
    }

    protected function tearDown(): void
    {
        if (config('database.connections.suporte.database') === config('database.connections.mysql.database')) {
            foreach (self::TABELAS as $t) {
                Schema::connection('suporte')->dropIfExists($t);
            }
            DB::purge('suporte');
        }
        parent::tearDown();
    }

    private function bancoSuporte(bool $comViews = true): void
    {
        config(['database.connections.suporte' => config('database.connections.mysql')]);
        DB::purge('suporte');
        $s = Schema::connection('suporte');
        foreach (self::TABELAS as $t) {
            $s->dropIfExists($t);
        }
        if (! $comViews) {
            return;
        }
        $s->create('v_chamados_solicitante', function (Blueprint $t) {
            $t->char('cpf', 11);
            $t->char('codigo', 26);
            $t->string('protocolo');
            $t->string('titulo', 150);
            $t->string('sistema');
            $t->string('status');
            $t->string('status_rotulo');
            $t->string('prioridade');
            $t->tinyInteger('aberto');
            $t->tinyInteger('aguardando_solicitante');
            $t->dateTime('criado_em');
            $t->dateTime('atualizado_em');
            $t->string('link');
        });
        $s->create('v_notificacoes_solicitante', function (Blueprint $t) {
            $t->char('cpf', 11);
            $t->unsignedBigInteger('id');
            $t->char('codigo', 26);
            $t->string('protocolo');
            $t->string('sistema');
            $t->string('tipo');
            $t->string('texto');
            $t->dateTime('criada_em');
            $t->string('link');
        });
        $ch = fn (string $cpf, string $codigo, string $protocolo, int $aberto, int $aguardando, string $atualizado, string $link = '') => [
            'cpf' => $cpf, 'codigo' => $codigo, 'protocolo' => $protocolo, 'titulo' => 'Chamado '.$protocolo, 'sistema' => 'ARSENAL',
            'status' => $aberto ? 'EM_ANALISE' : 'ENCERRADO', 'status_rotulo' => $aberto ? 'Em análise' : 'Encerrado', 'prioridade' => 'MEDIA',
            'aberto' => $aberto, 'aguardando_solicitante' => $aguardando, 'criado_em' => '2026-11-18 08:00:00', 'atualizado_em' => $atualizado,
            'link' => $link ?: self::URL.'/chamados/'.$codigo,
        ];
        $db = DB::connection('suporte');
        $db->table('v_chamados_solicitante')->insert([
            $ch(self::CPF, self::ULID, 'SUP-2026-000001', 1, 0, '2026-11-19 09:00:00'),
            $ch(self::CPF, '01JDCENTRALARSENAL00000002', 'SUP-2026-000002', 1, 1, '2026-11-18 09:00:00'),
            $ch(self::CPF, 'codigo-invalido', 'SUP-2026-000003', 0, 0, '2026-11-19 10:00:00', 'javascript:alert(1)'),
            $ch(self::OUTRO_CPF, '01JDCENTRALOUTRO0000000001', 'SUP-2026-000099', 1, 1, '2026-11-19 11:00:00'),
        ]);
        $db->table('v_notificacoes_solicitante')->insert([
            ['cpf' => self::CPF, 'id' => 7, 'codigo' => self::ULID, 'protocolo' => 'SUP-2026-000001', 'sistema' => 'ARSENAL',
                'tipo' => 'RESPOSTA_EQUIPE', 'texto' => 'A equipe respondeu o chamado SUP-2026-000001', 'criada_em' => '2026-11-19 09:00:00', 'link' => 'javascript:x'],
            ['cpf' => self::OUTRO_CPF, 'id' => 8, 'codigo' => '01JDCENTRALOUTRO0000000001', 'protocolo' => 'SUP-2026-000099', 'sistema' => 'ARSENAL',
                'tipo' => 'RESPOSTA_EQUIPE', 'texto' => 'Aviso de outra pessoa', 'criada_em' => '2026-11-19 11:00:00', 'link' => 'x'],
        ]);
    }

    public function test_exige_sessao(): void
    {
        $this->getJson('/api/v1/central-suporte')->assertUnauthorized();
    }

    public function test_conexao_mysql_com_timeout_e_leitura_desligada_por_padrao(): void
    {
        $c = config('database.connections.suporte');
        $this->assertSame('mysql', $c['driver']);
        if (extension_loaded('pdo_mysql')) {
            $this->assertSame(3, $c['options'][Mysql::ATTR_TIMEOUT] ?? null);
        }
        $padrao = require base_path('config/central_suporte.php');
        $this->assertSame('', $padrao['banco'], 'sem SUPORTE_DB_DATABASE a leitura fica desligada');
        $this->assertSame('ARSENAL', $padrao['sistema']);
    }

    public function test_so_chamados_e_avisos_do_cpf_da_sessao_e_sem_cpf_na_resposta(): void
    {
        $this->bancoSuporte();
        $r = $this->como($this->eu)->getJson('/api/v1/central-suporte?cpf='.self::OUTRO_CPF.'&login='.self::OUTRO_CPF)->assertOk();
        $r->assertJsonPath('disponivel', true)->assertJsonPath('abertos', 2)->assertJsonPath('nao_lidos', 1)
            ->assertJsonCount(3, 'chamados')->assertJsonCount(1, 'avisos')
            // abertos primeiro; entre eles, "Aguardando você" primeiro
            ->assertJsonPath('chamados.0.protocolo', 'SUP-2026-000002')->assertJsonPath('chamados.0.aguardando_voce', true)
            ->assertJsonPath('chamados.1.protocolo', 'SUP-2026-000001')
            ->assertJsonPath('chamados.1.link', self::URL.'/chamados/'.self::ULID)
            ->assertJsonPath('avisos.0.link', self::URL.'/chamados/'.self::ULID)
            ->assertJsonPath('url_chamados', self::URL.'/chamados');
        $corpo = $r->getContent();
        $this->assertStringNotContainsString('SUP-2026-000099', $corpo);
        $this->assertStringNotContainsString('Aviso de outra pessoa', $corpo);
        $this->assertStringNotContainsString(self::OUTRO_CPF, $corpo);
        $this->assertStringNotContainsString(self::CPF, $corpo, 'o CPF não volta na resposta');
        // link inválido/hostil da view nunca é repassado
        $this->assertStringNotContainsString('javascript:', $corpo);
        $r->assertJsonPath('chamados.2.link', self::URL.'/chamados');
    }

    public function test_cada_usuario_ve_so_o_seu_mesmo_com_cache(): void
    {
        $this->bancoSuporte();
        $this->como($this->eu)->getJson('/api/v1/central-suporte')->assertJsonCount(3, 'chamados');
        $this->como($this->outro)->getJson('/api/v1/central-suporte')->assertOk()
            ->assertJsonCount(1, 'chamados')->assertJsonPath('chamados.0.protocolo', 'SUP-2026-000099')->assertJsonPath('nao_lidos', 1);
        // conta cujo login não é CPF válido: nada é lido
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/central-suporte')->assertOk()
            ->assertJsonPath('disponivel', false)->assertJsonPath('chamados', []);
    }

    public function test_link_abrir_chamado_aponta_para_a_central_com_o_sistema_arsenal(): void
    {
        $this->bancoSuporte();
        $this->como($this->eu)->getJson('/api/v1/central-suporte')->assertOk()
            ->assertJsonPath('url_abrir', self::URL.'/novo?sistema=ARSENAL');
        // URL fora de http(s) não vira link
        Cache::flush();
        config(['central_suporte.url' => 'javascript:alert(1)']);
        $this->como($this->eu)->getJson('/api/v1/central-suporte')->assertOk()
            ->assertJsonPath('url_abrir', null)->assertJsonPath('url_chamados', null)->assertJsonPath('chamados.0.link', null);
    }

    public function test_sem_env_resposta_vazia_sem_tocar_no_banco(): void
    {
        $this->bancoSuporte();
        config(['central_suporte.banco' => '']);
        DB::flushQueryLog();
        DB::connection('suporte')->enableQueryLog();
        $this->como($this->eu)->getJson('/api/v1/central-suporte')->assertOk()
            ->assertJsonPath('disponivel', false)->assertJsonPath('chamados', [])->assertJsonPath('avisos', [])
            ->assertJsonPath('nao_lidos', 0)->assertJsonPath('url_abrir', self::URL.'/novo?sistema=ARSENAL');
        $this->assertSame([], DB::connection('suporte')->getQueryLog(), 'nenhuma consulta ao banco suporte');
    }

    public function test_banco_ausente_responde_vazio_sem_erro(): void
    {
        config(['database.connections.suporte' => ['database' => 'banco_suporte_que_nao_existe'] + config('database.connections.mysql')]);
        DB::purge('suporte');
        $this->como($this->eu)->getJson('/api/v1/central-suporte')->assertOk()
            ->assertJsonPath('disponivel', false)->assertJsonPath('chamados', [])->assertJsonPath('nao_lidos', 0)
            ->assertJsonPath('url_abrir', self::URL.'/novo?sistema=ARSENAL');
    }

    public function test_views_ausentes_respondem_vazio_sem_erro(): void
    {
        $this->bancoSuporte(false);
        $this->como($this->eu)->getJson('/api/v1/central-suporte')->assertOk()->assertJsonPath('disponivel', false)->assertJsonPath('avisos', []);
    }

    public function test_leitura_nao_grava_auditoria(): void
    {
        $this->bancoSuporte();
        $antes = DB::table('eventos_auditoria')->count();
        $this->como($this->eu)->getJson('/api/v1/central-suporte')->assertOk();
        $this->assertSame($antes, DB::table('eventos_auditoria')->count());
    }
}
