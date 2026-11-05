<?php

namespace Tests\Feature;

use App\Models\Categoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** RF-007, RF-008, RF-009, RF-029, UC-03, P-04. */
class CatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    public function test_gestor_cadastra_material_e_operador_nao(): void
    {
        $cat = Categoria::first();
        $dados = ['categoria_id' => $cat->id, 'codigo' => 'NOVO-01', 'nome' => 'Material fictício novo', 'modo_controle' => 'LOTE'];
        $this->como($this->c['operadorAlfa1'])->postJson('/api/v1/items', $dados)->assertForbidden();
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/items', $dados)->assertCreated()->assertJsonPath('modo_controle', 'LOTE');
        $this->postJson('/api/v1/items', $dados)->assertStatus(422); // código único
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'material.criado']);
    }

    public function test_modo_de_controle_e_imutavel_apos_estoque(): void
    {
        $m = $this->c['matInd'];
        $this->como($this->c['gestorAlfa'])->putJson("/api/v1/items/{$m->id}", ['modo_controle' => 'LOTE'])->assertOk();
        $this->putJson("/api/v1/items/{$m->id}", ['modo_controle' => 'INDIVIDUAL'])->assertOk();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'PAT-X-1'))->assertCreated();
        $this->putJson("/api/v1/items/{$m->id}", ['modo_controle' => 'LOTE'])->assertStatus(409)->assertJsonPath('error.code', 'MODO_CONTROLE_IMUTAVEL');
    }

    public function test_listas_de_referencia(): void
    {
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/catalog/fabricantes', ['nome' => 'Fabricante Fictício X'])->assertCreated();
        $this->getJson('/api/v1/catalog/fabricantes')->assertOk()->assertJsonFragment(['nome' => 'Fabricante Fictício X']);
        $this->getJson('/api/v1/catalog/postos-graduacoes')->assertOk()->assertJsonCount(12, 'data');
        $this->como($this->c['auditor'])->postJson('/api/v1/catalog/calibres', ['nome' => 'C1'])->assertForbidden();
    }

    public function test_busca_e_filtro_de_materiais(): void
    {
        $this->como($this->c['operadorAlfa1'])->getJson('/api/v1/items?modo_controle=LOTE')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.modo_controle', 'LOTE');
    }
}
