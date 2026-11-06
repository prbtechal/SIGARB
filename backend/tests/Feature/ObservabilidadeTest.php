<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** RNF-09, RNF-14 — logs estruturados e saúde. */
class ObservabilidadeTest extends TestCase
{
    use RefreshDatabase;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        $this->log = storage_path('framework/testing/sigarb-'.bin2hex(random_bytes(4)).'.json.log');
        @mkdir(dirname($this->log), 0777, true);
        config(['logging.channels.sigarb_json.driver' => 'single', 'logging.channels.sigarb_json.path' => $this->log]);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    /** @return list<array> */
    private function linhas(): array
    {
        return array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents($this->log))));
    }

    public function test_cada_requisicao_gera_linha_json_correlacionada_com_resposta_e_auditoria(): void
    {
        $r = $this->postJson('/api/v1/auth/login', ['login' => $this->c['gestorAlfa']->login, 'password' => 'senha-teste-1234'])->assertOk();
        $id = $r->headers->get('X-Request-Id');
        $l = collect($this->linhas())->firstWhere('message', 'requisicao');
        $this->assertSame($id, $l['context']['request_id'] ?? $l['extra']['request_id'] ?? null);
        $this->assertSame('POST', $l['context']['metodo']);
        $this->assertSame('api/v1/auth/login', $l['context']['rota']);
        $this->assertSame(200, $l['context']['status']);
        $this->assertIsFloat($l['context']['duracao_ms'] + 0.0);
        $this->assertNotNull($l['datetime']);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auth.login', 'request_id' => $id]);
    }

    public function test_log_nao_contem_senha_corpo_query_nem_ids_da_url(): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => 'alguem', 'password' => 'SENHA-SECRETA-XYZ-987']);
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/militares?q=NOME-SENSIVEL-ABC');
        $this->getJson('/api/v1/movements/987654');
        $bruto = (string) file_get_contents($this->log);
        foreach (['SENHA-SECRETA-XYZ-987', 'NOME-SENSIVEL-ABC', 'alguem', '987654', 'password', 'XSRF', 'cookie'] as $proibido) {
            $this->assertStringNotContainsStringIgnoringCase($proibido, $bruto, "vazou: $proibido");
        }
        $rotas = collect($this->linhas())->pluck('context.rota');
        $this->assertContains('api/v1/movements/{movimentacao}', $rotas->all());
        $erro = collect($this->linhas())->firstWhere('context.status', 404);
        $this->assertSame('NAO_ENCONTRADO', $erro['context']['erro']);
        $this->assertSame('WARNING', $erro['level_name']);
    }

    public function test_liveness_nao_depende_de_banco_nem_de_sessao(): void
    {
        $this->getJson('/api/v1/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_ok_e_sem_detalhes_internos(): void
    {
        Storage::fake('documentos');
        $r = $this->getJson('/api/v1/health/ready')->assertOk()
            ->assertExactJson(['status' => 'ok', 'verificacoes' => ['banco' => 'ok', 'armazenamento_documentos' => 'ok', 'espaco_em_disco' => 'ok']]);
        $c = $r->getContent();
        foreach ([config('database.connections.mysql.host'), config('database.connections.mysql.database'), storage_path(), 'mysql', 'SQLSTATE'] as $x) {
            $this->assertStringNotContainsString((string) $x, $c);
        }
        $this->assertSame([], Storage::disk('documentos')->allFiles('saude')); // sonda removida
    }

    public function test_readiness_reporta_falha_com_503_sem_vazar_excecao(): void
    {
        Storage::fake('documentos');
        config(['sigarb.saude.disco_minimo_bytes' => PHP_INT_MAX]);
        $r = $this->getJson('/api/v1/health/ready')->assertStatus(503)->assertJsonPath('status', 'indisponivel')->assertJsonPath('verificacoes.espaco_em_disco', 'falha');
        $this->assertStringNotContainsString('Exception', $r->getContent());
    }

    public function test_readiness_usa_cache_e_tem_limite_de_taxa(): void
    {
        Storage::fake('documentos');
        $this->getJson('/api/v1/health/ready')->assertOk();
        $this->assertTrue(Cache::has('sigarb:saude:pronto'));
        for ($i = 0; $i < 29; $i++) {
            $this->getJson('/api/v1/health/ready');
        }
        $this->getJson('/api/v1/health/ready')->assertStatus(429);
    }

    /** CP-8: com o banco fora do ar e os drivers de produção (sessão e cache em banco), liveness segue 200 e readiness
     *  responde 503 com "banco: falha" — nunca 500. Defeito encontrado no ambiente production-like. */
    public function test_banco_fora_do_ar_liveness_200_e_readiness_503(): void
    {
        Storage::fake('documentos');
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        $porta = config('database.connections.mysql.port');
        config(['database.connections.mysql.port' => 1]);
        DB::purge('mysql');
        try {
            $this->getJson('/api/v1/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
            $r = $this->getJson('/api/v1/health/ready')->assertStatus(503)
                ->assertJsonPath('status', 'indisponivel')->assertJsonPath('verificacoes.banco', 'falha');
            $this->assertStringNotContainsString('SQLSTATE', $r->getContent());
            $this->assertEmpty($r->headers->getCookies()); // sondas não abrem sessão
        } finally {
            config(['database.connections.mysql.port' => $porta]);
            DB::purge('mysql');
        }
    }
}
