<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use App\Services\AutorizacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** RF-003, RF-006, RN-024, RNF-03, UC-15 — escopo horizontal e vertical. */
class OrganizacaoEPermissoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    public function test_escopo_inclui_subarvore_e_exclui_outros_ramos(): void
    {
        $s = app(AutorizacaoService::class);
        $g = $this->c['gestorAlfa'];
        $this->assertTrue($s->pode($g, 'militares.gerir', $this->c['alfa']));
        $this->assertTrue($s->pode($g, 'militares.gerir', $this->c['alfa1']));
        $this->assertFalse($s->pode($g, 'militares.gerir', $this->c['bravo']));
        $this->assertFalse($s->pode($this->c['operadorAlfa1'], 'estoque.entrada', $this->c['alfa'])); // não sobe na árvore
        $this->assertFalse($s->pode($g, 'usuarios.gerir'));
        $this->assertTrue($s->global($this->c['admin'], 'usuarios.gerir'));
    }

    public function test_usuario_inativo_nao_tem_permissao(): void
    {
        $g = $this->c['gestorAlfa'];
        $g->ativo = false;
        $this->assertFalse(app(AutorizacaoService::class)->pode($g, 'estoque.ver'));
    }

    public function test_listagens_sao_filtradas_por_escopo_no_servidor(): void
    {
        $siglas = collect($this->como($this->c['gestorBravo'])->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('sigla');
        $this->assertEquals(['T-BRAVO'], $siglas->all());
        $locais = collect($this->como($this->c['gestorAlfa'])->getJson('/api/v1/locations')->json('data'))->pluck('id')->sort()->values();
        $this->assertEquals(collect([$this->c['localAlfa']->id, $this->c['localAlfa1']->id])->sort()->values(), $locais);
    }

    public function test_unidade_raiz_exige_escopo_global_e_subunidade_exige_gerir_superior(): void
    {
        $org = $this->c['alfa']->organizacao_id;
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/units', ['organizacao_id' => $org, 'sigla' => 'NOVA', 'nome' => 'Nova unidade', 'tipo' => 'UNIDADE'])->assertForbidden();
        $r = $this->como($this->c['admin'])->postJson('/api/v1/units', ['organizacao_id' => $org, 'unidade_superior_id' => $this->c['alfa1']->id, 'sigla' => 'T-ALFA-1-A', 'nome' => 'Fração fictícia', 'tipo' => 'SUBUNIDADE'])
            ->assertCreated()->assertJsonPath('nivel', 2);
        $this->assertDatabaseHas('unidades', ['id' => $r->json('id'), 'caminho' => $this->c['alfa1']->caminho.$r->json('id').'/']);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'unidade.criada', 'recurso_id' => $r->json('id')]);
    }

    public function test_acesso_negado_e_auditado(): void
    {
        $this->como($this->c['operadorAlfa1'])->postJson('/api/v1/locations', ['unidade_id' => $this->c['bravo']->id, 'codigo' => 'X-01', 'nome' => 'Invasor', 'tipo' => 'RESERVA'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACESSO_NEGADO');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'acesso.negado', 'resultado' => 'NEGADO', 'usuario_id' => $this->c['operadorAlfa1']->id]);
    }

    public function test_gestao_de_usuarios_e_papeis_com_segregacao(): void
    {
        $admin = $this->c['admin'];
        $novo = $this->como($admin)->postJson('/api/v1/users', ['nome' => 'Conta Fictícia', 'login' => 'conta.ficticia', 'password' => 'Senha-forte-123'])->assertCreated();
        $id = $novo->json('id');
        $this->postJson("/api/v1/users/$id/roles", ['papel' => 'OPERADOR', 'unidade_id' => $this->c['alfa']->id])->assertCreated()->assertJsonPath('atribuicoes.0.papel', 'OPERADOR');
        $this->postJson("/api/v1/users/$id/roles", ['papel' => 'OPERADOR', 'unidade_id' => $this->c['alfa']->id])->assertStatus(422);
        $this->postJson("/api/v1/users/{$admin->id}/roles", ['papel' => 'AUDITOR'])->assertForbidden(); // não altera os próprios papéis
        $this->putJson("/api/v1/users/{$admin->id}", ['ativo' => false])->assertStatus(422);
        $this->postJson('/api/v1/users', ['nome' => 'Fraca', 'login' => 'fraca', 'password' => 'curta'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['password']]]);
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/users')->assertForbidden();
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'acesso.papel.atribuido']);
        $this->assertFalse(str_contains(json_encode(EventoAuditoria::pluck('dados')), 'Senha-forte-123'));
    }

    public function test_operacoes_bloqueadas_por_pendencia_institucional(): void
    {
        $this->como($this->c['aprovador']);
        $this->bloquear('ACAUTELAMENTO', 'TRANSFERENCIA'); // CP-14: decididas; o portão segue testado
        foreach (['/api/v1/acautelamentos/requests', '/api/v1/transfers'] as $rota) {
            $this->postJson($rota, ['qualquer' => 'coisa'])->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
        }
        $this->assertDatabaseCount('movimentacoes', 0);
        $ops = collect($this->getJson('/api/v1/operations')->json('data'))->keyBy('codigo');
        $this->assertSame('HABILITADA', $ops['ENTRADA']['estado']);
        $this->assertSame('BLOQUEADA', $ops['ACAUTELAMENTO']['estado']);
    }
}
