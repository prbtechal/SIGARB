<?php

namespace Tests\Feature;

use App\Models\Militar;
use App\Models\TipoDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Arquivos;
use Tests\TestCase;

/** RF-024 — indicadores por permissão e escopo, sem vazamento por agregados. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        Storage::fake('documentos');
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 40));
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa1'], 'PAT-D1'));
        $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localBravo'], 7, 'LB'));
        $this->post('/api/v1/documents', ['arquivo' => Arquivos::arquivo('b.pdf', Arquivos::pdf()), 'tipo_documento_id' => TipoDocumento::value('id'), 'unidade_id' => $this->c['bravo']->id, 'titulo' => 'Doc Bravo'], ['Accept' => 'application/json'])->assertCreated();
        Militar::factory()->count(2)->create(['unidade_id' => $this->c['bravo']->id]);
    }

    public function test_gestor_ve_so_o_proprio_escopo(): void
    {
        $d = $this->como($this->c['gestorAlfa'])->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame(40, $d['estoque']['quantidade_em_lotes']);
        $this->assertSame(1, $d['estoque']['itens_individuais_disponiveis']);
        $this->assertSame(2, $d['unidades']['ativas']); // ALFA e ALFA-1
        $this->assertSame(0, $d['militares_ativos']);
        $this->assertSame([], $d['documentos']['por_situacao']);
        $this->assertArrayNotHasKey('auditoria', $d);
        $nat = collect($d['estoque']['por_natureza'])->pluck('valor', 'rotulo');
        $this->assertSame(41, (int) $nat->sum());
    }

    public function test_cada_bloco_exige_a_permissao_correspondente(): void
    {
        $adm = $this->como($this->c['admin'])->getJson('/api/v1/dashboard')->json();
        $this->assertArrayNotHasKey('estoque', $adm);
        $this->assertArrayNotHasKey('documentos', $adm);
        $this->assertArrayNotHasKey('auditoria', $adm);
        $aud = $this->como($this->c['auditor'])->getJson('/api/v1/dashboard')->json();
        $this->assertSame(47, $aud['estoque']['quantidade_em_lotes']);
        $this->assertTrue($aud['auditoria']['cadeia_integra']);
        $this->assertSame(1, collect($aud['documentos']['por_situacao'])->firstWhere('rotulo', 'ATIVO')['valor']);
        $local = $this->como($this->c['auditorAlfa'])->getJson('/api/v1/dashboard')->json();
        $this->assertArrayNotHasKey('cadeia_integra', $local['auditoria']); // verificação completa só global
        foreach ($local['auditoria']['atividade_recente'] as $e) {
            $this->assertNotSame('auth.login', $e['acao']); // eventos sem unidade não vazam para escopo local
        }
    }

    public function test_agregados_nao_expoem_dados_pessoais_nem_conteudo(): void
    {
        $json = $this->como($this->c['auditor'])->getJson('/api/v1/dashboard')->getContent();
        foreach (Militar::all() as $m) {
            $this->assertStringNotContainsString($m->matricula, $json);
            $this->assertStringNotContainsString($m->nome, $json);
        }
        $this->assertStringNotContainsString('%PDF', $json);
        $this->assertStringNotContainsString('sha256', $json);
        $this->assertStringNotContainsString('armazenamento', $json);
    }

    public function test_sem_indicadores_de_operacoes_bloqueadas(): void
    {
        $d = $this->como($this->c['auditor'])->getJson('/api/v1/dashboard')->json();
        $txt = json_encode(array_keys($d));
        foreach (['acautel', 'entrega', 'devoluc', 'transfer', 'inspec', 'manutenc'] as $p) {
            $this->assertStringNotContainsStringIgnoringCase($p, $txt);
        }
        $this->assertSame(11, $d['operacoes_bloqueadas']);
    }

    public function test_exige_autenticacao(): void
    {
        auth('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/dashboard')->assertStatus(401);
    }
}
