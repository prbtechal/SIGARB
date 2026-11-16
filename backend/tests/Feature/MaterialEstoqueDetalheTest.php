<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET /stock/materials/{id}: catálogo + saldo no escopo + atalhos conforme permissão; filtro de movimentações por material. */
class MaterialEstoqueDetalheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->assertCreated();
        $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localBravo'], 7, 'LB'))->assertCreated();
    }

    public function test_saldo_no_escopo_e_acoes_do_gestor(): void
    {
        $id = $this->c['matLote']->id;
        $r = $this->como($this->c['gestorAlfa'])->getJson("/api/v1/stock/materials/$id")->assertOk();
        $r->assertJsonPath('id', $id)->assertJsonPath('codigo', $this->c['matLote']->codigo)->assertJsonCount(1, 'saldos')
            ->assertJsonPath('saldos.0.local.id', $this->c['localAlfa']->id)->assertJsonPath('total.quantidade', 5)
            ->assertJsonPath('pode.editar_catalogo', true)->assertJsonPath('pode.entrada', true)->assertJsonPath('pode.operar', true);
    }

    public function test_auditor_global_ve_todos_os_locais_sem_acoes(): void
    {
        $this->como($this->c['auditor'])->getJson("/api/v1/stock/materials/{$this->c['matLote']->id}")->assertOk()
            ->assertJsonCount(2, 'saldos')->assertJsonPath('total.quantidade', 12)
            ->assertJsonPath('pode.editar_catalogo', false)->assertJsonPath('pode.entrada', false)->assertJsonPath('pode.operar', false);
    }

    public function test_sem_permissao_de_estoque_e_negado(): void
    {
        $this->como($this->c['admin'])->getJson("/api/v1/stock/materials/{$this->c['matLote']->id}")->assertForbidden();
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/stock/materials/999999')->assertNotFound();
    }

    public function test_movimentacoes_filtradas_por_material_no_escopo(): void
    {
        $this->como($this->c['gestorAlfa'])->getJson("/api/v1/movements?material_id={$this->c['matLote']->id}")->assertOk()->assertJsonPath('meta.total', 1);
        $this->como($this->c['auditor'])->getJson("/api/v1/movements?material_id={$this->c['matLote']->id}")->assertOk()->assertJsonPath('meta.total', 2);
        $this->como($this->c['auditor'])->getJson("/api/v1/movements?material_id={$this->c['matInd']->id}")->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_filtro_por_local_e_busca_por_numero_de_serie(): void
    {
        $this->como($this->c['auditor'])->getJson("/api/v1/stock/materials/{$this->c['matLote']->id}?local_id={$this->c['localBravo']->id}")->assertOk()
            ->assertJsonCount(1, 'saldos')->assertJsonPath('saldos.0.local.id', $this->c['localBravo']->id)->assertJsonPath('total.quantidade', 7);
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'X', 'SERIE-ABC-77'))->assertCreated();
        $r = $this->getJson('/api/v1/stock/balances?q=ABC-77')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.material.id', $this->c['matInd']->id);
        $this->assertArrayHasKey('tipo', $r->json('data.0.material'));
        $this->getJson('/api/v1/stock/individual-items?q=ABC-77')->assertJsonPath('data.0.numero_serie', 'SERIE-ABC-77');
    }
}
