<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use App\Services\AuditoriaService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RF-025, RF-027, RNF-04, RN-017, RN-020, UC-14, ADR-007. */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    public function test_cadeia_de_hash_integra_e_verificavel(): void
    {
        $s = app(AuditoriaService::class);
        $a = $s->registrar('teste.um', dados: ['x' => 1]);
        $b = $s->registrar('teste.dois');
        $this->assertSame($a->hash, $b->hash_anterior);
        $this->assertNull($s->verificar());
        $this->artisan('sigarb:auditoria:verificar')->assertExitCode(0);
        $this->como($this->c['auditor'])->getJson('/api/v1/audit-events/integrity')->assertOk()->assertJsonPath('integra', true);
        $this->como($this->c['auditorAlfa'])->getJson('/api/v1/audit-events/integrity')->assertForbidden();
    }

    public function test_adulteracao_e_remocao_sao_bloqueadas_pelo_banco(): void
    {
        app(AuditoriaService::class)->registrar('teste.imutavel');
        foreach (["UPDATE eventos_auditoria SET acao = 'x'", 'DELETE FROM eventos_auditoria'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('Trigger deveria bloquear: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->expectException(\LogicException::class);
        EventoAuditoria::first()->update(['acao' => 'y']);
    }

    public function test_verificacao_detecta_evento_forjado_fora_da_cadeia(): void
    {
        $s = app(AuditoriaService::class);
        $s->registrar('legitimo');
        DB::table('eventos_auditoria')->insert(['ocorrido_em' => now(), 'acao' => 'forjado', 'resultado' => 'SUCESSO', 'hash_anterior' => str_repeat('f', 64), 'hash' => str_repeat('e', 64)]);
        $this->assertNotNull($s->verificar());
    }

    public function test_sem_ponteiro_da_cadeia_a_escrita_auditada_falha_fechada(): void
    {
        DB::table('auditoria_cadeia')->delete();
        $this->expectException(\RuntimeException::class);
        app(AuditoriaService::class)->registrar('teste.sem_ponteiro');
    }

    public function test_sanitizacao_remove_campos_secretos(): void
    {
        $e = app(AuditoriaService::class)->registrar('teste', dados: ['password' => 'p', 'aninhado' => ['token' => 't', 'ok' => 'v']]);
        $this->assertSame('[REMOVIDO]', $e->dados['password']);
        $this->assertSame('[REMOVIDO]', $e->dados['aninhado']['token']);
        $this->assertSame('v', $e->dados['aninhado']['ok']);
    }

    public function test_consulta_por_filtros_e_escopo_do_auditor(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5));
        $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localBravo'], 5, 'LB'));

        $global = $this->como($this->c['auditor'])->getJson('/api/v1/audit-events?acao=estoque.entrada')->assertOk();
        $this->assertSame(2, $global->json('meta.total'));
        $this->assertArrayNotHasKey('hash_anterior', $global->json('data.0'));
        $this->como($this->c['auditorAlfa'])->getJson('/api/v1/audit-events?acao=estoque.entrada')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.unidade_id', $this->c['alfa']->id);
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/audit-events')->assertForbidden();
        $this->como($this->c['auditor'])->getJson('/api/v1/audit-events?resultado=INVALIDO')->assertStatus(422);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'auditoria.consulta', 'usuario_id' => $this->c['auditor']->id]);
    }

    public function test_respostas_de_erro_seguem_contrato_e_nao_vazam_detalhes(): void
    {
        $r = $this->como($this->c['auditor'])->getJson('/api/v1/movements/999999')->assertNotFound();
        $r->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
        $this->assertStringNotContainsString('Illuminate', $r->getContent());
        $this->assertNotEmpty($r->headers->get('X-Request-Id'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }
}
