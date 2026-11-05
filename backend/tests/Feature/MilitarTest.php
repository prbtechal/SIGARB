<?php

namespace Tests\Feature;

use App\Models\Militar;
use App\Models\PostoGraduacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** RF-005, RF-026, §2.1, ADR-009, UC-03 (cadastro auditado). */
class MilitarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    private function dados(array $extra = []): array
    {
        return array_merge(['unidade_id' => $this->c['alfa1']->id, 'posto_graduacao_id' => PostoGraduacao::where('sigla', 'CB')->value('id'),
            'nome' => 'Pessoa Fictícia Teste', 'nome_guerra' => 'FICTICIO', 'matricula' => 'TST-1001'], $extra);
    }

    public function test_gestor_cadastra_no_escopo_e_evento_e_auditado(): void
    {
        $r = $this->como($this->c['gestorAlfa'])->postJson('/api/v1/militares', $this->dados())->assertCreated()
            ->assertJsonPath('matricula', 'TST-1001')->assertJsonPath('unidade.sigla', 'T-ALFA-1');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'militar.criado', 'recurso_id' => $r->json('id'), 'unidade_id' => $this->c['alfa1']->id]);
    }

    public function test_cadastro_fora_do_escopo_e_negado(): void
    {
        $this->como($this->c['gestorBravo'])->postJson('/api/v1/militares', $this->dados())->assertForbidden();
        $this->como($this->c['operadorAlfa1'])->postJson('/api/v1/militares', $this->dados())->assertForbidden();
        $this->assertDatabaseCount('militares', 0);
    }

    public function test_validacao_e_unicidade_da_matricula(): void
    {
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/militares', $this->dados(['matricula' => "x'; DROP TABLE militares;--"]))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDACAO')->assertJsonStructure(['error' => ['fields' => ['matricula']]]);
        $this->postJson('/api/v1/militares', $this->dados())->assertCreated();
        $this->postJson('/api/v1/militares', $this->dados(['nome' => 'Outra Pessoa']))->assertStatus(422);
    }

    public function test_listagem_respeita_escopo_mascara_matricula_e_pagina(): void
    {
        Militar::factory()->count(3)->create(['unidade_id' => $this->c['alfa1']->id]);
        Militar::factory()->count(2)->create(['unidade_id' => $this->c['bravo']->id]);
        $r = $this->como($this->c['operadorAlfa1'])->getJson('/api/v1/militares?per_page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)->assertJsonCount(2, 'data');
        $this->assertStringStartsWith('•••', $r->json('data.0.matricula'));
        $this->assertFalse($r->json('data.0.matricula_completa'));
        $g = $this->como($this->c['gestorAlfa'])->getJson('/api/v1/militares')->json('data');
        $this->assertTrue($g[0]['matricula_completa']);
        $this->getJson('/api/v1/militares?per_page=1000')->assertStatus(422);
        $this->getJson('/api/v1/militares?sort=password')->assertStatus(422);
    }

    public function test_acesso_horizontal_a_detalhe_de_outra_unidade_e_negado(): void
    {
        $m = Militar::factory()->create(['unidade_id' => $this->c['bravo']->id]);
        $this->como($this->c['gestorAlfa'])->getJson("/api/v1/militares/{$m->id}")->assertForbidden();
        $this->como($this->c['gestorBravo'])->getJson("/api/v1/militares/{$m->id}")->assertOk();
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'militar.consulta_detalhe', 'recurso_id' => $m->id]);
    }

    public function test_busca_textual_trata_curingas_como_literais(): void
    {
        Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'nome' => 'Nome Com % Literal']);
        Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'nome' => 'Outro Nome']);
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/militares?q=%25')->assertJsonPath('meta.total', 1);
    }

    public function test_busca_por_matricula_nao_vira_oraculo_para_quem_ve_mascarada(): void
    {
        Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id, 'matricula' => 'SEGREDO-77']);
        $this->como($this->c['operadorAlfa1'])->getJson('/api/v1/militares?q=SEGREDO-77')->assertJsonPath('meta.total', 0);
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/militares?q=SEGREDO-77')->assertJsonPath('meta.total', 1);
    }

    public function test_mover_militar_para_unidade_fora_do_escopo_e_negado(): void
    {
        $m = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id]);
        $this->como($this->c['gestorAlfa'])->putJson("/api/v1/militares/{$m->id}", ['unidade_id' => $this->c['bravo']->id])->assertForbidden();
        $this->putJson("/api/v1/militares/{$m->id}", ['situacao' => 'AFASTADO'])->assertOk()->assertJsonPath('situacao', 'AFASTADO');
    }

    public function test_militar_existe_sem_conta_e_vinculo_e_unico(): void
    {
        $m = Militar::factory()->create(['unidade_id' => $this->c['alfa1']->id]);
        $this->assertNull($m->usuario);
        $admin = $this->como($this->c['admin']);
        $admin->putJson("/api/v1/users/{$this->c['operadorAlfa1']->id}", ['militar_id' => $m->id])->assertOk()->assertJsonPath('militar.id', $m->id);
        $admin->putJson("/api/v1/users/{$this->c['gestorAlfa']->id}", ['militar_id' => $m->id])->assertStatus(422);
    }
}
