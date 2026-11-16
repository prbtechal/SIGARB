<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** GET /api/v1/sobre: público, versão do arquivo VERSION registrada no banco ecossistema, equipe atual e ex-integrantes. */
class SobreEcossistemaTest extends TestCase
{
    private function prepararEcossistema(): void
    {
        config(['database.connections.ecossistema.database' => config('database.connections.mysql.database')]);
        DB::purge('ecossistema');
        $s = Schema::connection('ecossistema');
        foreach (['participacoes', 'pessoas', 'versoes', 'sistemas'] as $t) {
            $s->dropIfExists("$t");
        }
        $s->create('sistemas', fn (Blueprint $t) => [$t->smallIncrements('id'), $t->string('codigo', 30)->unique(), $t->string('nome', 80)]);
        $s->create('versoes', function (Blueprint $t) {
            $t->id(); $t->unsignedSmallInteger('sistema_id'); $t->string('versao', 20); $t->char('git_sha', 40)->default('');
            $t->dateTime('implantado_em')->useCurrent(); $t->unique(['sistema_id', 'versao', 'git_sha']);
        });
        $s->create('pessoas', fn (Blueprint $t) => [$t->increments('id'), $t->string('nome', 120)->unique()]);
        $s->create('participacoes', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('pessoa_id'); $t->unsignedSmallInteger('sistema_id')->nullable();
            $t->enum('grupo', ['GOVERNANCA', 'DESENVOLVIMENTO']); $t->string('cargo', 120); $t->string('atribuicao')->nullable();
            $t->date('inicio')->nullable(); $t->date('fim')->nullable(); $t->smallInteger('ordem')->default(0);
        });
        $db = DB::connection('ecossistema');
        $db->table('sistemas')->insert([['id' => 1, 'codigo' => 'ARSENAL', 'nome' => 'Arsenal (SIGARB)'], ['id' => 2, 'codigo' => 'ESCALA_CERTA', 'nome' => 'Escala Certa']]);
        $db->table('pessoas')->insert([['id' => 1, 'nome' => 'Gestora'], ['id' => 2, 'nome' => 'Dev atual'], ['id' => 3, 'nome' => 'Dev antigo'], ['id' => 4, 'nome' => 'Dev de outro sistema']]);
        $db->table('participacoes')->insert([
            ['pessoa_id' => 1, 'sistema_id' => null, 'grupo' => 'GOVERNANCA', 'cargo' => 'Superintendente', 'fim' => null, 'ordem' => 1],
            ['pessoa_id' => 2, 'sistema_id' => null, 'grupo' => 'DESENVOLVIMENTO', 'cargo' => 'Desenvolvedor', 'fim' => null, 'ordem' => 1],
            ['pessoa_id' => 3, 'sistema_id' => 1, 'grupo' => 'DESENVOLVIMENTO', 'cargo' => 'Desenvolvedor', 'fim' => '2026-01-31', 'ordem' => 2],
            ['pessoa_id' => 4, 'sistema_id' => 2, 'grupo' => 'DESENVOLVIMENTO', 'cargo' => 'Desenvolvedor', 'fim' => null, 'ordem' => 3],
        ]);
    }

    protected function tearDown(): void
    {
        if (config('database.connections.ecossistema.database') === config('database.connections.mysql.database')) {
            foreach (['participacoes', 'pessoas', 'versoes', 'sistemas'] as $t) {
                Schema::connection('ecossistema')->dropIfExists($t);
            }
        }
        parent::tearDown();
    }

    public function test_sobre_publico_registra_versao_e_separa_equipe_atual_de_ex_integrantes(): void
    {
        Cache::flush();
        $this->prepararEcossistema();
        $versao = trim(file_get_contents(base_path('VERSION')));

        $r = $this->getJson('/api/v1/sobre')->assertOk(); // sem login
        $r->assertJsonPath('versao', $versao)->assertJsonPath('sistema', 'Arsenal (SIGARB)')
            ->assertJsonPath('governanca.0.nome', 'Gestora')
            ->assertJsonPath('equipe', [['nome' => 'Dev atual', 'cargo' => 'Desenvolvedor', 'atribuicao' => null, 'inicio' => null, 'fim' => null]])
            ->assertJsonPath('ex_integrantes.0.nome', 'Dev antigo');
        $this->assertNotNull($r->json('implantado_em'));
        $this->assertSame(1, DB::connection('ecossistema')->table('versoes')->where('sistema_id', 1)->count());

        Cache::flush();
        $this->getJson('/api/v1/sobre')->assertOk();
        $this->assertSame(1, DB::connection('ecossistema')->table('versoes')->count(), 'mesma versão não duplica');
    }

    public function test_sem_banco_ecossistema_devolve_so_a_versao(): void
    {
        Cache::flush();
        config(['database.connections.ecossistema.database' => 'banco_que_nao_existe']);
        DB::purge('ecossistema');

        $this->getJson('/api/v1/sobre')->assertOk()->assertJsonPath('equipe', null)->assertJsonPath('versao', trim(file_get_contents(base_path('VERSION'))));
    }
}
