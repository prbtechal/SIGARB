<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use App\Services\AuditoriaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ADR-015 — o Arsenal grava, exibe e exporta na hora de Maceió (UTC-3), como a VM e os demais sistemas PRB. */
class FusoMaceioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        // 2026-11-19 17:46 UTC = 14:46 em Maceió
        $this->travelTo(CarbonImmutable::parse('2026-11-19T17:46:00Z'));
    }

    public function test_app_e_sessao_do_banco_em_maceio(): void
    {
        $this->assertSame('America/Maceio', config('app.timezone'));
        $this->assertSame('America/Maceio', date_default_timezone_get());
        $this->assertSame('-03:00', DB::selectOne('SELECT @@session.time_zone AS tz')->tz);
        $this->getJson('/api/v1/health/ready')->assertOk()->assertJsonPath('verificacoes.fuso', 'ok');
    }

    public function test_registro_novo_grava_hora_local_no_banco_e_api_devolve_offset(): void
    {
        $this->como($this->c['gestorAlfa']);
        $id = $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->assertCreated()->json('id');

        $cru = DB::selectOne('SELECT LEFT(ocorrido_em, 19) AS ocorrido_em, LEFT(created_at, 19) AS created_at FROM movimentacoes WHERE id = ?', [$id]);
        $this->assertSame('2026-11-19 14:46:00', $cru->ocorrido_em);
        $this->assertSame('2026-11-19 14:46:00', $cru->created_at);
        $this->assertSame('2026-11-19T14:46:00-03:00', $this->getJson("/api/v1/movements/$id")->assertOk()->json('ocorrido_em'));

        $evento = EventoAuditoria::orderByDesc('id')->first();
        $this->assertStringStartsWith('2026-11-19 14:46:00', $evento->getRawOriginal('ocorrido_em'));
        $this->assertNull(app(AuditoriaService::class)->verificar());
    }

    public function test_data_informada_com_fuso_e_gravada_em_hora_local(): void
    {
        $this->como($this->c['gestorAlfa']);
        $corpo = $this->entradaLote($this->c['localAlfa'], 2, 'L-RETRO') + ['ocorrido_em' => '2026-11-19T11:00:00-03:00', 'justificativa' => 'Recebida no plantão anterior'];
        $id = $this->withHeaders($this->chave())->postJson('/api/v1/movements', $corpo)->assertCreated()->json('id');
        $this->assertSame('2026-11-19 11:00:00', DB::selectOne('SELECT LEFT(ocorrido_em, 19) v FROM movimentacoes WHERE id = ?', [$id])->v);

        // Mesmo instante enviado em UTC: grava a mesma hora local.
        $corpo['ocorrido_em'] = '2026-11-19T14:00:00Z';
        $corpo['itens'][0]['lote_codigo'] = 'L-RETRO-2';
        $id2 = $this->withHeaders($this->chave())->postJson('/api/v1/movements', $corpo)->assertCreated()->json('id');
        $this->assertSame('2026-11-19 11:00:00', DB::selectOne('SELECT LEFT(ocorrido_em, 19) v FROM movimentacoes WHERE id = ?', [$id2])->v);
    }

    public function test_exportacao_sai_em_hora_local(): void
    {
        $this->como($this->c['gestorAlfa']);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->assertCreated();
        $r = $this->get('/api/v1/reports/movimentacoes')->assertOk();
        $this->assertStringContainsString('sigarb-movimentacoes-20261119-144600.csv', (string) $r->headers->get('content-disposition'));
        $this->assertStringContainsString('2026-11-19 14:46:00', $r->streamedContent());
    }

    public function test_datas_cruas_de_agregacao_saem_com_offset_correto(): void
    {
        $this->como($this->c['gestorAlfa']);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['origem_descricao' => 'Fornecedor X'] + $this->entradaLote($this->c['localAlfa'], 5))->assertCreated();
        $origens = $this->getJson('/api/v1/stock/origins?tipo=ENTRADA')->assertOk()->json('data');
        $x = collect($origens)->firstWhere('valor', 'Fornecedor X');
        $this->assertSame('2026-11-19T14:46:00-03:00', $x['ultimo_uso']);
    }
}
