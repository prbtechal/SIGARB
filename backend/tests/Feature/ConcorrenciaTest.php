<?php

namespace Tests\Feature;

use App\Models\Movimentacao;
use App\Models\SaldoEstoque;
use App\Services\EstoqueService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RF-013, RN-002, RNF-06, SRS §11.1 (idempotência), §13 (concorrência).
 * Processos PHP separados, cada um com conexão própria, disparados no mesmo instante.
 */
class ConcorrenciaTest extends TestCase
{
    /** O ponteiro da cadeia não é truncado (é reiniciado por limparBanco). */
    protected $exceptTables = ['auditoria_cadeia'];

    use DatabaseTruncation, \Tests\Support\LimpaBancoAposTeste;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('auditoria_cadeia')->updateOrInsert(['id' => 1], ['ultimo_hash' => str_repeat('0', 64)]);
        $this->cenario();
    }

    protected function tearDown(): void
    {
        $this->limparBanco();
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('framework/testing/docs-concorrencia'));
        parent::tearDown();
    }

    /** @return list<array> */
    private function disparar(array $jobs): array
    {
        $inicio = microtime(true) + 2.0;
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'sigarb_test', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'SIGARB_DOCUMENTOS_ROOT' => storage_path('framework/testing/docs-concorrencia')];
        $ps = [];
        foreach ($jobs as [$op, $uid, $payload, $chave]) {
            $p = new Process(['php', __DIR__.'/../Concorrencia/worker.php', $op, (string) $uid, json_encode($payload), $chave, (string) $inicio], base_path(), $env, null, 60);
            $p->start();
            $ps[] = $p;
        }

        return array_map(function (Process $p) {
            $p->wait();
            $r = json_decode(trim($p->getOutput()), true);
            $this->assertIsArray($r, 'Saída do worker: '.$p->getOutput().$p->getErrorOutput());

            return $r;
        }, $ps);
    }

    public function test_requisicoes_simultaneas_com_mesma_chave_geram_uma_unica_movimentacao(): void
    {
        $chave = (string) Str::uuid();
        $d = $this->entradaLote($this->c['localAlfa'], 10);
        $r = $this->disparar(array_fill(0, 6, ['entrada', $this->c['gestorAlfa']->id, $d, $chave]));
        $this->assertCount(6, array_filter($r, fn ($x) => $x['ok']), json_encode($r));
        $this->assertSame(5, count(array_filter($r, fn ($x) => $x['replay'] ?? false)));
        $this->assertSame(1, Movimentacao::count());
        $this->assertSame(10, (int) SaldoEstoque::sum('quantidade'));
    }

    public function test_entradas_simultaneas_no_mesmo_lote_somam_sem_perda(): void
    {
        $jobs = [];
        for ($i = 1; $i <= 8; $i++) {
            $jobs[] = ['entrada', $this->c['gestorAlfa']->id, $this->entradaLote($this->c['localAlfa'], $i), (string) Str::uuid()];
        }
        $r = $this->disparar($jobs);
        $this->assertCount(8, array_filter($r, fn ($x) => $x['ok']), json_encode($r));
        $this->assertSame(36, (int) SaldoEstoque::sum('quantidade'));
        $this->assertSame(1, SaldoEstoque::count());
    }

    /** Regressão do deadlock (CP-6): entradas concorrentes em lote NOVO nunca são recusadas por ciclo de locks. */
    public function test_entradas_simultaneas_em_lote_novo_nao_sao_recusadas_por_deadlock(): void
    {
        for ($rodada = 1; $rodada <= 3; $rodada++) {
            $jobs = [];
            for ($i = 1; $i <= 10; $i++) {
                $jobs[] = ['entrada', $this->c['gestorAlfa']->id, $this->entradaLote($this->c['localAlfa'], 1, "L-NOVO-$rodada"), (string) Str::uuid()];
            }
            $r = $this->disparar($jobs);
            $this->assertCount(10, array_filter($r, fn ($x) => $x['ok']), "rodada $rodada: ".json_encode($r));
        }
        $this->assertSame(30, (int) SaldoEstoque::sum('quantidade'));
        $this->assertNull(app(\App\Services\AuditoriaService::class)->verificar());
    }

    public function test_estornos_simultaneos_da_mesma_entrada_so_um_vence(): void
    {
        $this->actingAs($this->c['gestorAlfa']);
        [$mov] = app(EstoqueService::class)->registrarEntrada($this->c['gestorAlfa'], $this->entradaLote($this->c['localAlfa'], 50), (string) Str::uuid());
        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = ['estorno', $this->c['gestorAlfa']->id, ['id' => $mov->id], (string) Str::uuid()];
        }
        $r = $this->disparar($jobs);
        $this->assertSame(1, count(array_filter($r, fn ($x) => $x['ok'])), json_encode($r));
        foreach (array_filter($r, fn ($x) => ! $x['ok']) as $x) {
            $this->assertSame('JA_ESTORNADA', $x['code']);
        }
        $this->assertSame(0, (int) SaldoEstoque::sum('quantidade'));
        $this->assertSame(1, Movimentacao::where('tipo', 'ESTORNO')->count());
    }

    public function test_debitos_simultaneos_nunca_tornam_saldo_negativo(): void
    {
        $this->actingAs($this->c['gestorAlfa']);
        app(EstoqueService::class)->registrarEntrada($this->c['gestorAlfa'], $this->entradaLote($this->c['localAlfa'], 100), (string) Str::uuid());
        $s = SaldoEstoque::first();
        $r = $this->disparar(array_fill(0, 10, ['debito', $this->c['gestorAlfa']->id, ['local' => $s->local_estoque_id, 'lote' => $s->lote_id, 'qtd' => 15], 'x']));
        $ok = count(array_filter($r, fn ($x) => $x['ok']));
        $this->assertSame(6, $ok, json_encode($r));
        foreach (array_filter($r, fn ($x) => ! $x['ok']) as $x) {
            $this->assertSame('SALDO_INSUFICIENTE', $x['code']);
        }
        $this->assertSame(10, $s->fresh()->quantidade);
    }

    public function test_uploads_simultaneos_identicos_geram_documentos_e_arquivos_distintos(): void
    {
        $origem = storage_path('framework/testing/upload-origem.pdf');
        @mkdir(dirname($origem), 0777, true);
        file_put_contents($origem, \Tests\Support\Arquivos::pdf('% concorrente'));
        $dados = ['arquivo' => $origem, 'tipo_documento_id' => \App\Models\TipoDocumento::value('id'), 'unidade_id' => $this->c['alfa']->id, 'titulo' => 'Upload concorrente'];
        $r = $this->disparar(array_fill(0, 6, ['documento', $this->c['gestorAlfa']->id, $dados, 'x']));
        $this->assertCount(6, array_filter($r, fn ($x) => $x['ok']), json_encode($r));
        $docs = \App\Models\Documento::all();
        $this->assertCount(6, $docs);
        $this->assertCount(6, $docs->pluck('armazenamento_id')->unique());
        $this->assertCount(1, $docs->pluck('sha256')->unique());
        foreach ($docs as $d) {
            $this->assertSame(\Tests\Support\Arquivos::pdf('% concorrente'), file_get_contents(storage_path('framework/testing/docs-concorrencia/'.$d->caminho())));
        }
        $this->assertNull(app(\App\Services\AuditoriaService::class)->verificar(), 'cadeia de auditoria íntegra sob escrita concorrente');
        @unlink($origem);
    }
}
