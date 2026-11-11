<?php

namespace Tests\Feature;

use App\Models\Calibre;
use App\Models\Categoria;
use App\Models\Fabricante;
use App\Models\ItemIndividual;
use App\Models\Material;
use App\Models\Militar;
use App\Models\MilitarUnidade;
use App\Models\PostoGraduacao;
use App\Models\TipoMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CP-12 — tipos de material e perfis de campos, atributos de colete, conservação unificada, dados funcionais do militar,
 * vínculos secundários temporais, alertas de validade e consulta de material vinculado.
 */
class Cp12EstruturalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    private function tipo(string $perfil, ?Categoria $cat = null, string $nome = 'Tipo teste'): TipoMaterial
    {
        return TipoMaterial::create(['categoria_id' => ($cat ?? Categoria::firstOrCreate(['codigo' => 'CAT-TESTE'], ['nome' => 'Categoria teste', 'natureza' => 'EQUIPAMENTO']))->id, 'nome' => $nome, 'perfil' => $perfil, 'ativo' => true]);
    }

    private function colete(): Material
    {
        $t = $this->tipo('COLETE', null, 'Colete balístico');

        return Material::factory()->create(['categoria_id' => $t->categoria_id, 'tipo_id' => $t->id, 'fabricante_id' => Fabricante::create(['nome' => 'Fab '.uniqid()])->id, 'modo_controle' => 'INDIVIDUAL']);
    }

    private function entradaColete(Material $m, array $item): array
    {
        return ['tipo' => 'ENTRADA', 'local_destino_id' => $this->c['localAlfa']->id, 'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-CP12',
            'itens' => [['material_id' => $m->id] + $item]];
    }

    // ---- Tipos de material ----

    public function test_tipo_unico_por_categoria_imutavel_em_uso_e_categoria_nova_ganha_tipo_geral(): void
    {
        $cat = Categoria::firstOrCreate(['codigo' => 'CAT-TESTE'], ['nome' => 'Categoria teste', 'natureza' => 'EQUIPAMENTO']);
        $outra = Categoria::create(['codigo' => 'C'.uniqid(), 'nome' => 'Categoria teste', 'natureza' => 'EQUIPAMENTO']);
        $this->como($this->c['admin']);
        $id = $this->postJson('/api/v1/reference-lists/tipos-material', ['categoria_id' => $cat->id, 'nome' => 'Coldre', 'perfil' => 'ACESSORIO'])->assertCreated()->json('id');
        $this->postJson('/api/v1/reference-lists/tipos-material', ['categoria_id' => $cat->id, 'nome' => 'Coldre', 'perfil' => 'ACESSORIO'])->assertStatus(422);
        $this->postJson('/api/v1/reference-lists/tipos-material', ['categoria_id' => $outra->id, 'nome' => 'Coldre', 'perfil' => 'ACESSORIO'])->assertCreated();
        $this->postJson('/api/v1/reference-lists/tipos-material', ['categoria_id' => $cat->id, 'nome' => 'X', 'perfil' => 'INVENTADO'])->assertStatus(422);
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/reference-lists/tipos-material', ['categoria_id' => $cat->id, 'nome' => 'Sem permissão', 'perfil' => 'GERAL'])->assertForbidden();

        Material::factory()->create(['categoria_id' => $cat->id, 'tipo_id' => $id]);
        $this->como($this->c['admin'])->putJson("/api/v1/reference-lists/tipos-material/$id", ['perfil' => 'GERAL'])->assertStatus(409)->assertJsonPath('error.code', 'VALOR_EM_USO');
        $this->putJson("/api/v1/reference-lists/tipos-material/$id", ['nome' => 'Coldre de cintura'])->assertOk();
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'referencia.tipos-material.criada']);

        // Desativado: some dos formulários e não é aceito em material novo
        $this->postJson("/api/v1/reference-lists/tipos-material/$id/deactivate", ['justificativa' => 'Substituído por outro tipo'])->assertOk();
        $this->getJson("/api/v1/catalog/tipos-material?categoria_id={$cat->id}")->assertOk()->assertJsonMissing(['id' => $id]);
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/items', ['categoria_id' => $cat->id, 'tipo_id' => $id, 'codigo' => 'COL-9', 'nome' => 'Coldre', 'modo_controle' => 'LOTE'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['tipo_id']]]);

        $nova = $this->como($this->c['admin'])->postJson('/api/v1/reference-lists/categorias', ['codigo' => 'EPI-T', 'nome' => 'EPI teste', 'natureza' => 'EQUIPAMENTO'])->assertCreated()->json('id');
        $this->assertDatabaseHas('tipos_material', ['categoria_id' => $nova, 'nome' => 'Geral', 'perfil' => 'GERAL']);
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/items', ['categoria_id' => $nova, 'codigo' => 'EPI-1', 'nome' => 'Capacete', 'modo_controle' => 'INDIVIDUAL'])
            ->assertCreated()->assertJsonPath('tipo.nome', 'Geral'); // sem tipo: compatível, usa "Geral"
    }

    public function test_perfil_do_tipo_exige_campos_e_rejeita_inaplicaveis(): void
    {
        $arma = $this->tipo('ARMA_FOGO', null, 'Pistola');
        $base = ['categoria_id' => $arma->categoria_id, 'tipo_id' => $arma->id, 'codigo' => 'PST-1', 'nome' => 'Pistola fictícia'];
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/items', $base + ['modo_controle' => 'LOTE', 'compatibilidade' => 'qualquer'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['modo_controle', 'fabricante_id', 'modelo', 'calibre_id', 'compatibilidade']]]);
        $ok = $base + ['modo_controle' => 'INDIVIDUAL', 'fabricante_id' => Fabricante::create(['nome' => 'Fab '.uniqid()])->id, 'modelo' => 'M1', 'calibre_id' => Calibre::create(['nome' => 'Cal '.uniqid()])->id, 'marca' => 'Marca X'];
        $this->postJson('/api/v1/items', $ok)->assertCreated()->assertJsonPath('tipo.perfil', 'ARMA_FOGO')->assertJsonPath('marca', 'Marca X');

        $outraCat = Categoria::create(['codigo' => 'C'.uniqid(), 'nome' => 'Categoria teste', 'natureza' => 'EQUIPAMENTO']);
        $this->postJson('/api/v1/items', ['categoria_id' => $outraCat->id, 'codigo' => 'PST-2'] + $ok)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['tipo_id']]]);

        // Acessório: compatibilidade aceita, modo livre (individual ou lote em materiais distintos)
        $ac = $this->tipo('ACESSORIO', null, 'Coldre');
        $this->postJson('/api/v1/items', ['categoria_id' => $ac->categoria_id, 'tipo_id' => $ac->id, 'codigo' => 'CLD-1', 'nome' => 'Coldre', 'modo_controle' => 'LOTE', 'compatibilidade' => 'Pistola .40'])->assertCreated();
        $this->postJson('/api/v1/items', ['categoria_id' => $ac->categoria_id, 'tipo_id' => $ac->id, 'codigo' => 'CLD-2', 'nome' => 'Coldre numerado', 'modo_controle' => 'INDIVIDUAL'])->assertCreated();

        // Material legado (sem tipo) continua editável sem as regras novas
        $legado = $this->c['matInd'];
        $this->assertNull($legado->tipo_id);
        $this->putJson("/api/v1/items/{$legado->id}", ['nome' => 'Renomeado'])->assertOk();
        // ...mas não pode "perder" o tipo depois de ter um
        $id = Material::where('codigo', 'PST-1')->value('id');
        $this->putJson("/api/v1/items/$id", ['tipo_id' => null])->assertStatus(422);
    }

    // ---- Colete: atributos do item físico ----

    public function test_colete_exige_tamanho_nivel_e_fabricacao_e_valida_datas(): void
    {
        $m = $this->colete();
        $this->como($this->c['gestorAlfa']);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaColete($m, ['numero_serie' => 'COL-1']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['itens.0.tamanho', 'itens.0.nivel_protecao', 'itens.0.fabricado_em']]]);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaColete($m, ['numero_serie' => 'COL-1', 'tamanho' => 'G', 'nivel_protecao' => 'IIIA', 'fabricado_em' => '2024-01-10', 'validade' => '2023-01-01']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['itens.0.validade']]]);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaColete($m, ['numero_serie' => 'COL-1', 'tamanho' => 'G', 'nivel_protecao' => 'XYZ', 'fabricado_em' => '2024-01-10']))
            ->assertStatus(422);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaColete($m, ['numero_serie' => 'COL-1', 'tamanho' => 'G', 'nivel_protecao' => 'IIIA',
            'fabricado_em' => '2024-01-10', 'validade' => '2029-01-10', 'estado_conservacao' => 'NOVO']))->assertCreated();
        $i = ItemIndividual::where('numero_serie', 'COL-1')->first();
        $this->assertSame(['G', 'IIIA', '2024-01-10', '2029-01-10', 'DISPONIVEL'], [$i->tamanho, $i->nivel_protecao, $i->fabricado_em->toDateString(), $i->validade->toDateString(), $i->situacao]);

        // Item de material comum não aceita atributos de colete
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['itens' => [['material_id' => $this->c['matInd']->id, 'numero_serie' => 'X-1', 'nivel_protecao' => 'II']]] + $this->entradaColete($m, []))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['itens.0.nivel_protecao']]]);

        // Banco também impede validade anterior à fabricação
        $this->expectException(QueryException::class);
        DB::table('itens_individuais')->where('id', $i->id)->update(['validade' => '2020-01-01']);
    }

    public function test_alertas_e_filtro_de_validade_respeitam_escopo_e_nao_mudam_situacao(): void
    {
        $m = $this->colete();
        $this->como($this->c['gestorAlfa']);
        foreach (['VENC-1' => now()->subDay(), 'PROX-1' => now()->addDays(30), 'OK-1' => now()->addYears(3)] as $id => $val) {
            $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaColete($m, ['numero_serie' => $id, 'tamanho' => 'M', 'nivel_protecao' => 'II',
                'fabricado_em' => now()->subYears(4)->toDateString(), 'validade' => $val->toDateString()]))->assertCreated();
        }
        $alertas = collect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->keyBy('tipo');
        $this->assertSame(1, $alertas['ITEM_VENCIDO']['quantidade']);
        $this->assertSame(1, $alertas['ITEM_VENCENDO']['quantidade']);
        $this->getJson('/api/v1/stock/individual-items?validade=VENCIDO')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.numero_serie', 'VENC-1')->assertJsonPath('data.0.validade_estado', 'VENCIDO')->assertJsonPath('data.0.situacao', 'DISPONIVEL');
        $this->getJson('/api/v1/stock/individual-items?perfil=COLETE')->assertJsonPath('meta.total', 3);

        // Outra unidade não vê nem conta
        $this->como($this->c['gestorBravo']);
        $this->assertArrayNotHasKey('ITEM_VENCIDO', collect($this->getJson('/api/v1/alerts')->json('data'))->keyBy('tipo')->all());
        $this->getJson('/api/v1/stock/individual-items?validade=VENCIDO')->assertJsonPath('meta.total', 0);
    }

    // ---- Conservação ----

    public function test_conservacao_unificada_e_atualizacao_autorizada_sem_mudar_situacao(): void
    {
        $this->como($this->c['gestorAlfa']);
        $e = $this->entradaIndividual($this->c['localAlfa'], 'CONS-1');
        $e['itens'][0]['estado_conservacao'] = 'OTIMO';
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $e)->assertStatus(422);
        $e['itens'][0]['estado_conservacao'] = 'DANIFICADO';
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $e)->assertCreated();
        $item = ItemIndividual::where('numero_serie', 'CONS-1')->first();
        $this->assertSame('DANIFICADO', $item->estado_conservacao);

        $this->patchJson("/api/v1/stock/individual-items/{$item->id}/conservation", ['estado_conservacao' => 'INSERVIVEL'])->assertStatus(422); // justificativa
        $this->patchJson("/api/v1/stock/individual-items/{$item->id}/conservation", ['estado_conservacao' => 'INSERVIVEL', 'justificativa' => 'Avaliação técnica fictícia'])
            ->assertOk()->assertJsonPath('anterior', 'DANIFICADO');
        $item->refresh();
        $this->assertSame(['INSERVIVEL', 'DISPONIVEL'], [$item->estado_conservacao, $item->situacao]);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'item.conservacao_alterada', 'recurso_id' => $item->id]);
        $this->patchJson("/api/v1/stock/individual-items/{$item->id}/conservation", ['estado_conservacao' => 'INSERVIVEL', 'justificativa' => 'Repetição sem mudança'])->assertStatus(409);
        // Situação operacional não é aceita por este caminho
        $this->patchJson("/api/v1/stock/individual-items/{$item->id}/conservation", ['estado_conservacao' => 'BOM', 'situacao' => 'BAIXADO', 'justificativa' => 'Tentativa de baixa direta'])->assertOk();
        $this->assertSame('DISPONIVEL', $item->fresh()->situacao);

        $this->como($this->c['gestorBravo'])->patchJson("/api/v1/stock/individual-items/{$item->id}/conservation", ['estado_conservacao' => 'RUIM', 'justificativa' => 'Fora do escopo teste'])->assertNotFound();
        $this->como($this->c['auditorAlfa'])->patchJson("/api/v1/stock/individual-items/{$item->id}/conservation", ['estado_conservacao' => 'RUIM', 'justificativa' => 'Sem permissão teste'])->assertForbidden();
    }

    // ---- Militar: dados funcionais e privacidade ----

    public function test_militar_dados_funcionais_opcionais_contatos_restritos_e_auditoria_mascarada(): void
    {
        $base = ['unidade_id' => $this->c['alfa1']->id, 'posto_graduacao_id' => PostoGraduacao::where('sigla', 'CB')->value('id'), 'nome' => 'Pessoa Fictícia', 'nome_guerra' => 'FICT', 'matricula' => 'TST-2001'];
        $this->como($this->c['gestorAlfa']);
        $this->postJson('/api/v1/militares', $base + ['email_institucional' => 'nao-e-email'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email_institucional']]]);
        $this->postJson('/api/v1/militares', $base + ['telefone_institucional' => 'abc'])->assertStatus(422);
        $id = $this->postJson('/api/v1/militares', $base)->assertCreated()->json('id'); // todos opcionais
        $this->putJson("/api/v1/militares/$id", ['quadro' => 'Quadro fictício', 'funcao' => 'Armeiro', 'telefone_institucional' => '(00) 0000-0000',
            'email_institucional' => 'armeiro@exemplo.invalid', 'observacoes_administrativas' => 'Nota fictícia'])->assertOk()
            ->assertJsonPath('email_institucional', 'armeiro@exemplo.invalid')->assertJsonPath('funcao', 'Armeiro');

        $ev = DB::table('eventos_auditoria')->where('acao', 'militar.atualizado')->latest('id')->value('dados');
        $this->assertStringNotContainsString('armeiro@exemplo.invalid', $ev);
        $this->assertStringNotContainsString('0000-0000', $ev);
        $this->assertStringContainsString('Armeiro', $ev);

        // Sem militares.ver_detalhe: vê quadro/função, não vê contatos nem observações
        $this->como($this->c['operadorAlfa1'])->getJson("/api/v1/militares/$id")->assertOk()
            ->assertJsonPath('funcao', 'Armeiro')->assertJsonPath('email_institucional', null)->assertJsonPath('observacoes_administrativas', null);
        $this->assertSame(1, Militar::count());
        $this->assertFalse(\Schema::hasColumn('militares', 'cpf')); // CPF não é coletado (P-09)
    }

    // ---- Vínculos secundários ----

    public function test_vinculos_secundarios_temporais_sem_ampliar_acesso(): void
    {
        $m = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);
        $url = "/api/v1/militares/{$m->id}/secondary-units";
        $this->como($this->c['gestorAlfa']);
        $this->postJson($url, ['unidade_id' => $this->c['alfa']->id])->assertStatus(422)->assertJsonPath('error.code', 'UNIDADE_PRINCIPAL');
        $this->postJson($url, ['unidade_id' => $this->c['bravo']->id])->assertStatus(422)->assertJsonPath('error.code', 'UNIDADE_INVALIDA'); // fora do escopo
        $this->postJson($url, ['unidade_id' => $this->c['alfa1']->id, 'inicio' => now()->addDay()->toDateString()])->assertStatus(422); // futuro
        $v = $this->postJson($url, ['unidade_id' => $this->c['alfa1']->id, 'inicio' => now()->subMonth()->toDateString()])->assertCreated()->assertJsonPath('ativo', true)->json('id');
        $this->postJson($url, ['unidade_id' => $this->c['alfa1']->id])->assertStatus(409)->assertJsonPath('error.code', 'VINCULO_DUPLICADO');
        $this->putJson("/api/v1/militares/{$m->id}", ['unidade_id' => $this->c['alfa1']->id])->assertStatus(409)->assertJsonPath('error.code', 'UNIDADE_EM_VINCULO_SECUNDARIO');

        // Vínculo secundário não dá visibilidade: escopo continua pela unidade principal
        $this->como($this->c['gestorBravo'])->getJson("/api/v1/militares/{$m->id}")->assertForbidden();
        $this->como($this->c['operadorAlfa1'])->postJson($url, ['unidade_id' => $this->c['alfa1']->id])->assertForbidden();

        $this->como($this->c['gestorAlfa'])->postJson("$url/$v/end", ['fim' => now()->subMonths(2)->toDateString()])->assertStatus(422)->assertJsonPath('error.code', 'PERIODO_INVALIDO');
        $this->postJson("$url/$v/end", [])->assertOk()->assertJsonPath('ativo', false);
        $this->postJson("$url/$v/end", [])->assertStatus(409);
        $this->postJson($url, ['unidade_id' => $this->c['alfa1']->id])->assertCreated(); // novo período após encerrar
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(2, DB::table('eventos_auditoria')->where('acao', 'like', 'militar.vinculo_secundario_%')->where('recurso_id', $m->id)->whereIn('acao', ['militar.vinculo_secundario_criado'])->count());

        // Banco: sem DELETE, sem reabrir/alterar vínculo encerrado, um ativo por unidade
        foreach ([fn () => DB::table('militar_unidades')->where('id', $v)->delete(),
            fn () => DB::table('militar_unidades')->where('id', $v)->update(['fim' => null]),
            fn () => MilitarUnidade::create(['militar_id' => $m->id, 'unidade_id' => $this->c['alfa1']->id, 'inicio' => now()->toDateString(), 'registrado_por_id' => $this->c['gestorAlfa']->id])] as $op) {
            try {
                $op();
                $this->fail('Operação deveria ser bloqueada pelo banco.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- Material vinculado (RF-VIN-06) ----

    public function test_material_vinculado_combina_entregas_e_filtra_no_escopo(): void
    {
        config(['sigarb.operacoes_provisorias' => ['ENTREGA', 'DEVOLUCAO']]);
        $efetivo = Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);
        $this->como($this->c['gestorAlfa']);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'VIN-1'))->assertCreated();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 50))->assertCreated();
        $item = ItemIndividual::where('numero_serie', 'VIN-1')->value('id');
        $lote = DB::table('lotes')->value('id');
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa']->id, 'militar_id' => $efetivo->id, 'justificativa' => 'Serviço',
            'previsao_retorno' => now()->addDays(3)->toDateString(), 'itens' => [['item_individual_id' => $item], ['lote_id' => $lote, 'quantidade' => 10]]])->assertCreated();

        $this->getJson("/api/v1/stock/linked?militar_id={$efetivo->id}")->assertOk()->assertJsonPath('meta.total', 2)->assertJsonMissingPath('data.0.militar.matricula');
        $this->getJson('/api/v1/stock/linked?modo_controle=LOTE')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.quantidade', 10);
        $this->getJson('/api/v1/stock/linked?situacao=ENTREGUE')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.referencia', ItemIndividual::find($item)->identificador);
        $this->como($this->c['gestorBravo'])->getJson('/api/v1/stock/linked')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/stock/linked?custodia=QUALQUER')->assertStatus(422);
    }
}
