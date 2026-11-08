<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\EventoAuditoria;
use App\Models\Fabricante;
use App\Models\TipoDocumento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Arquivos;
use Tests\TestCase;

/** RF-029, RN-023 (parcial), RN-025 — administração de listas de referência. */
class ReferenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    public function test_somente_escopo_global_com_referencias_gerir(): void
    {
        foreach (['gestorAlfa', 'auditor', 'operadorAlfa1', 'aprovador'] as $p) {
            $this->como($this->c[$p])->getJson('/api/v1/reference-lists/fabricantes')->assertForbidden();
            $this->postJson('/api/v1/reference-lists/fabricantes', ['nome' => 'Invasor'])->assertForbidden();
        }
        // ADMIN com escopo de unidade (não global) também não administra
        $adminLocal = $this->usuarioCom('ADMIN', $this->c['alfa']->id);
        $this->como($adminLocal)->getJson('/api/v1/reference-lists/fabricantes')->assertForbidden();
        $this->como($this->c['admin'])->getJson('/api/v1/reference-lists')->assertOk()->assertJsonCount(6, 'data')
            ->assertJsonFragment(['tipo' => 'tipos-material', 'possui_codigo' => false]) // CP-12
            ->assertJsonFragment(['tipo' => 'niveis-protecao', 'possui_codigo' => false]); // CP-13
        $this->getJson('/api/v1/reference-lists/postos-graduacoes')->assertNotFound(); // lista institucional não editável
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'acesso.negado', 'usuario_id' => $this->c['gestorAlfa']->id]);
    }

    public function test_criar_editar_com_validacao_unicidade_e_auditoria(): void
    {
        $this->como($this->c['admin']);
        $id = $this->postJson('/api/v1/reference-lists/tipos-documento', ['codigo' => 'GUIA', 'nome' => 'Guia fictícia'])->assertCreated()
            ->assertJsonPath('ativo', true)->assertJsonPath('em_uso', 0)->json('id');
        $this->postJson('/api/v1/reference-lists/tipos-documento', ['codigo' => 'GUIA', 'nome' => 'Outra'])->assertStatus(422);
        $this->postJson('/api/v1/reference-lists/tipos-documento', ['codigo' => 'guia minúscula', 'nome' => 'X'])->assertStatus(422);
        $this->postJson('/api/v1/reference-lists/categorias', ['codigo' => 'CAT-X', 'nome' => 'Categoria X', 'natureza' => 'FOGUETE'])->assertStatus(422);
        $this->postJson('/api/v1/reference-lists/fabricantes', ['nome' => 'Fab', 'ativo' => false])->assertStatus(422); // não cria desativado/ativado à força
        $this->putJson("/api/v1/reference-lists/tipos-documento/$id", ['nome' => 'Guia de remessa fictícia'])->assertOk()->assertJsonPath('nome', 'Guia de remessa fictícia');
        $ev = EventoAuditoria::where('acao', 'referencia.tipos-documento.atualizada')->firstOrFail();
        $this->assertSame('Guia fictícia', $ev->dados['antes']['nome']);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'referencia.tipos-documento.criada', 'recurso_id' => $id]);
    }

    public function test_valor_em_uso_nao_muda_significado_mas_nome_pode_ser_corrigido(): void
    {
        $cat = $this->c['matInd']->categoria;
        $this->como($this->c['admin'])->putJson("/api/v1/reference-lists/categorias/{$cat->id}", ['natureza' => 'MUNICAO'])
            ->assertStatus(409)->assertJsonPath('error.code', 'VALOR_EM_USO');
        $this->putJson("/api/v1/reference-lists/categorias/{$cat->id}", ['codigo' => 'NOVO-COD'])->assertStatus(409);
        $this->putJson("/api/v1/reference-lists/categorias/{$cat->id}", ['nome' => 'Nome corrigido'])->assertOk();
        $lista = $this->getJson('/api/v1/reference-lists/categorias')->json('data');
        $this->assertGreaterThan(0, collect($lista)->firstWhere('id', $cat->id)['em_uso']);
    }

    public function test_desativacao_sem_exclusao_bloqueia_uso_novo_e_preserva_registros(): void
    {
        Storage::fake('documentos');
        $tipo = TipoDocumento::where('codigo', 'OFICIO')->first();
        $fab = Fabricante::create(['nome' => 'Fabricante a desativar']);
        $this->como($this->c['admin'])->postJson("/api/v1/reference-lists/tipos-documento/{$tipo->id}/deactivate", ['justificativa' => 'curta'])->assertStatus(422);
        $this->postJson("/api/v1/reference-lists/tipos-documento/{$tipo->id}/deactivate", ['justificativa' => 'Substituído por tipo oficial'])->assertOk()->assertJsonPath('ativo', false);
        $this->postJson("/api/v1/reference-lists/fabricantes/{$fab->id}/deactivate", ['justificativa' => 'Cadastro duplicado de teste'])->assertOk();
        $this->postJson("/api/v1/reference-lists/fabricantes/{$fab->id}/deactivate", ['justificativa' => 'Cadastro duplicado de teste'])->assertStatus(409);

        // não aparece nos formulários e não é aceito em cadastros novos
        $this->como($this->c['gestorAlfa']);
        $this->assertNotContains($tipo->id, array_column($this->getJson('/api/v1/document-types')->json('data'), 'id'));
        $this->assertNotContains($fab->id, array_column($this->getJson('/api/v1/catalog/fabricantes')->json('data'), 'id'));
        $this->post('/api/v1/documents', ['arquivo' => Arquivos::arquivo('o.pdf', Arquivos::pdf()), 'tipo_documento_id' => $tipo->id, 'unidade_id' => $this->c['alfa']->id, 'titulo' => 'Ofício'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['tipo_documento_id']]]);
        $this->postJson('/api/v1/items', ['categoria_id' => Categoria::value('id'), 'fabricante_id' => $fab->id, 'codigo' => 'MAT-FAB', 'nome' => 'Mat', 'modo_controle' => 'LOTE'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['fabricante_id']]]);

        // nada foi apagado; reativação auditada
        $this->assertDatabaseHas('tipos_documento', ['id' => $tipo->id, 'ativo' => false]);
        $this->como($this->c['admin'])->postJson("/api/v1/reference-lists/tipos-documento/{$tipo->id}/reactivate", ['justificativa' => 'Desativado por engano'])->assertOk()->assertJsonPath('ativo', true);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'referencia.tipos-documento.desativada']);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'referencia.tipos-documento.reativada']);
    }

    public function test_listagem_busca_filtro_e_paginacao(): void
    {
        foreach (range(1, 5) as $i) {
            Fabricante::create(['nome' => "Fabricante Demo $i", 'ativo' => $i !== 5]);
        }
        $this->como($this->c['admin']);
        $this->getJson('/api/v1/reference-lists/fabricantes?q=Demo&per_page=2')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/v1/reference-lists/fabricantes?q=Demo&ativo=0')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/reference-lists/fabricantes?q=%25')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/reference-lists/fabricantes?sort=em_uso; DROP')->assertStatus(422);
    }

    public function test_configuracao_nao_desbloqueia_operacoes_institucionais(): void
    {
        $this->como($this->c['admin']);
        $this->postJson('/api/v1/reference-lists/operacoes', ['nome' => 'ACAUTELAMENTO'])->assertNotFound();
        DB::table('papeis')->count(); // sanidade
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/acautelamentos/requests', [])->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
        $this->como($this->c['admin'])->getJson('/api/v1/operations')->assertJsonFragment(['codigo' => 'ACAUTELAMENTO', 'estado' => 'BLOQUEADA']);
    }
}
