<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use App\Services\AuditoriaService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\LimpaBancoAposTeste;
use Tests\TestCase;

/**
 * ADR-007 — revisão do modelo de ameaças da cadeia de hash (docs/seguranca/AUDITORIA.md).
 * Simula um ator com privilégio de DDL (remove triggers). DDL faz commit implícito, por isso não usa RefreshDatabase.
 */
class AuditoriaAdulteracaoTest extends TestCase
{
    /** O ponteiro da cadeia não é truncado (é reiniciado por limparBanco). */
    protected $exceptTables = ['auditoria_cadeia'];

    use DatabaseTruncation, LimpaBancoAposTeste;

    private AuditoriaService $s;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('auditoria_cadeia')->updateOrInsert(['id' => 1], ['ultimo_hash' => str_repeat('0', 64)]);
        $this->s = app(AuditoriaService::class);
        foreach (['um', 'dois', 'tres', 'quatro'] as $a) {
            $this->s->registrar("teste.$a", dados: ['n' => $a]);
        }
        $this->assertNull($this->s->verificar());
        DB::unprepared('DROP TRIGGER IF EXISTS trg_auditoria_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_auditoria_no_delete');
    }

    protected function tearDown(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_auditoria_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_auditoria_no_delete');
        DB::unprepared("CREATE TRIGGER trg_auditoria_no_update BEFORE UPDATE ON eventos_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: auditoria append-only'");
        DB::unprepared("CREATE TRIGGER trg_auditoria_no_delete BEFORE DELETE ON eventos_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: auditoria append-only'");
        $this->limparBanco();
        parent::tearDown();
    }

    private function ids(): array
    {
        return EventoAuditoria::orderBy('id')->pluck('id')->all();
    }

    public function test_alteracao_de_conteudo_e_detectada(): void
    {
        $alvo = $this->ids()[1];
        DB::table('eventos_auditoria')->where('id', $alvo)->update(['dados' => json_encode(['n' => 'forjado'])]);
        $this->assertSame($alvo, $this->s->verificar());
        $this->artisan('sigarb:auditoria:verificar')->assertExitCode(1);
    }

    public function test_remocao_de_evento_intermediario_e_detectada(): void
    {
        $ids = $this->ids();
        DB::table('eventos_auditoria')->where('id', $ids[1])->delete();
        $this->assertSame($ids[2], $this->s->verificar());
    }

    public function test_alterar_acao_ou_ator_e_detectado(): void
    {
        $alvo = $this->ids()[2];
        DB::table('eventos_auditoria')->where('id', $alvo)->update(['acao' => 'teste.inocente']);
        $this->assertSame($alvo, $this->s->verificar());
    }

    public function test_remocao_do_ultimo_evento_so_e_detectada_pelo_ponteiro_da_cadeia(): void
    {
        $ids = $this->ids();
        DB::table('eventos_auditoria')->where('id', end($ids))->delete();
        // A varredura da tabela não percebe truncamento no fim…
        $this->assertNull($this->s->verificar());
        // …mas o ponteiro auditoria_cadeia ainda aponta para o hash removido.
        $ultimo = EventoAuditoria::orderByDesc('id')->value('hash');
        $this->assertNotSame($ultimo, DB::table('auditoria_cadeia')->value('ultimo_hash'));
    }

    /** LIMITAÇÃO DOCUMENTADA: quem controla registros E hashes pode reescrever a cadeia sem detecção local. */
    public function test_limitacao_reescrita_completa_por_administrador_privilegiado_nao_e_detectada(): void
    {
        $alvo = $this->ids()[1];
        DB::table('eventos_auditoria')->where('id', $alvo)->update(['dados' => json_encode(['n' => 'forjado'])]);
        $anterior = AuditoriaService::GENESIS;
        foreach (EventoAuditoria::orderBy('id')->get() as $e) {
            $attr = $e->getAttributes();
            $attr['dados'] = $e->dados;
            $attr['ocorrido_em'] = $e->ocorrido_em->format('Y-m-d H:i:s.u');
            $novo = AuditoriaService::calcularHash($anterior, $attr);
            DB::table('eventos_auditoria')->where('id', $e->id)->update(['hash_anterior' => $anterior, 'hash' => $novo]);
            $anterior = $novo;
        }
        DB::table('auditoria_cadeia')->update(['ultimo_hash' => $anterior]);
        $this->assertNull($this->s->verificar(), 'Sem âncora externa, a reescrita total passa na verificação local (ver proposta em AUDITORIA.md).');
    }
}
