<?php

namespace Tests\Feature;

use App\Models\ItemIndividual;
use App\Models\Militar;
use App\Models\Movimentacao;
use App\Models\PostoGraduacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CP-13 — decisões do responsável (P-03/P-05/P-09), papel Armeiro, devolução parcial, níveis de proteção administráveis
 * e importação/exportação de cadastros por planilha CSV.
 */
class Cp13ArmeiroImportacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]); // nada depende do portão provisório
        $this->cenario();
        $this->c['armeiro'] = $this->usuarioCom('ARMEIRO', $this->c['alfa']->id);
    }

    private function entrega(array $itens, ?string $retorno = '+3 days', ?Militar $m = null): TestResponse
    {
        $m ??= Militar::factory()->create(['unidade_id' => $this->c['alfa']->id]);

        return $this->withHeaders($this->chave())->postJson('/api/v1/movements', array_filter(['tipo' => 'ENTREGA', 'local_origem_id' => $this->c['localAlfa']->id,
            'militar_id' => $m->id, 'justificativa' => 'Serviço de rotina', 'previsao_retorno' => $retorno ? now()->modify($retorno)->toDateString() : null, 'itens' => $itens]));
    }

    private function devolve(int $entrega, ?array $itens = null): TestResponse
    {
        return $this->withHeaders($this->chave())->postJson('/api/v1/movements', array_filter(['tipo' => 'DEVOLUCAO', 'vinculo_id' => $entrega, 'itens' => $itens]));
    }

    private function csv(string $conteudo): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('planilha.csv', "\xEF\xBB\xBF".$conteudo);
    }

    // ---- P-05 + Armeiro ----

    public function test_armeiro_entrega_e_recebe_devolucao_parcial_ate_concluir_sem_portao_provisorio(): void
    {
        $this->como($this->c['armeiro']);
        foreach (['A-1', 'A-2'] as $id) {
            $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], $id))->assertCreated();
        }
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 100))->assertCreated();
        [$a1, $a2] = ItemIndividual::orderBy('identificador')->pluck('id')->all();
        $lote = DB::table('lotes')->value('id');

        $e = $this->entrega([['item_individual_id' => $a1], ['item_individual_id' => $a2], ['lote_id' => $lote, 'quantidade' => 30]])->assertCreated()->json('id');
        $this->assertFalse((bool) Movimentacao::find($e)->provisoria);

        // Parcial: um item (com estado registrado) e 10 unidades
        $this->devolve($e, [['item_individual_id' => $a1, 'estado_conservacao' => 'REGULAR'], ['lote_id' => $lote, 'quantidade' => 10]])->assertCreated();
        $this->assertSame(['DISPONIVEL', 'REGULAR'], [ItemIndividual::find($a1)->situacao, ItemIndividual::find($a1)->estado_conservacao]);
        $this->assertSame('ENTREGUE', ItemIndividual::find($a2)->situacao);
        $this->getJson("/api/v1/movements/$e")->assertJsonCount(1, 'pendente_devolucao.itens')->assertJsonPath('pendente_devolucao.itens.0.item_individual_id', $a2)
            ->assertJsonPath('pendente_devolucao.lotes.0.quantidade', 20);
        $this->getJson('/api/v1/movements?pendente=1&tipo=ENTREGA')->assertJsonPath('meta.total', 1);

        // Inválidos: item já devolvido, quantidade acima do pendente
        $this->devolve($e, [['item_individual_id' => $a1]])->assertStatus(422)->assertJsonPath('error.code', 'ITEM_INVALIDO');
        $this->devolve($e, [['lote_id' => $lote, 'quantidade' => 21]])->assertStatus(422)->assertJsonPath('error.code', 'QUANTIDADE_EXCEDIDA');

        // Sem lista: devolve o restante e conclui
        $this->devolve($e)->assertCreated();
        $this->assertSame('DISPONIVEL', ItemIndividual::find($a2)->situacao);
        $this->assertSame(100, (int) DB::table('saldos_estoque')->where('lote_id', $lote)->value('quantidade'));
        $this->getJson('/api/v1/movements?pendente=1&tipo=ENTREGA')->assertJsonPath('meta.total', 0);
        $this->devolve($e)->assertStatus(409)->assertJsonPath('error.code', 'JA_CONCLUIDA');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'estoque.devolucao']);
    }

    public function test_consumo_sem_retorno_nao_admite_devolucao(): void
    {
        $this->como($this->c['armeiro'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 50))->assertCreated();
        $e = $this->entrega([['lote_id' => DB::table('lotes')->value('id'), 'quantidade' => 5]], null)->assertCreated()->json('id');
        $this->devolve($e)->assertStatus(422)->assertJsonPath('error.code', 'SEM_RETORNO');
    }

    public function test_armeiro_nao_faz_baixa_nem_saida_e_transferencia_segue_bloqueada(): void
    {
        $this->como($this->c['armeiro'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'B-1'))->assertCreated();
        $item = ItemIndividual::value('id');
        $base = ['local_origem_id' => $this->c['localAlfa']->id, 'justificativa' => 'Tentativa teste', 'documento_referencia' => 'DOC-1', 'itens' => [['item_individual_id' => $item]]];
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'BAIXA'] + $base)->assertForbidden();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'SAIDA', 'destinacao' => 'Doação'] + $base)->assertForbidden();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'TRANSFERENCIA', 'local_destino_id' => $this->c['localBravo']->id] + $base)
            ->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
        // Fora do escopo do armeiro
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localBravo'], 'B-9'))->assertForbidden();
    }

    // ---- P-03 ----

    public function test_vencido_e_inservivel_nao_sao_entregues_nem_reservados(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 10, 'L-V', now()->subDay()->toDateString()))->assertCreated();
        $lote = DB::table('lotes')->value('id');
        $this->entrega([['lote_id' => $lote, 'quantidade' => 1]])->assertStatus(409)->assertJsonPath('error.code', 'ITEM_VENCIDO');
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'RESERVA', 'local_origem_id' => $this->c['localAlfa']->id, 'justificativa' => 'Reserva teste',
            'itens' => [['lote_id' => $lote, 'quantidade' => 1]]])->assertStatus(409)->assertJsonPath('error.code', 'ITEM_VENCIDO');

        $e = $this->entradaIndividual($this->c['localAlfa'], 'INS-1');
        $e['itens'][0]['estado_conservacao'] = 'INSERVIVEL';
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $e)->assertCreated();
        $this->entrega([['item_individual_id' => ItemIndividual::value('id')]])->assertStatus(409)->assertJsonPath('error.code', 'ITEM_INSERVIVEL');
        // Baixa continua possível para o vencido/inservível
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', ['tipo' => 'BAIXA', 'local_origem_id' => $this->c['localAlfa']->id, 'justificativa' => 'Inservível',
            'documento_referencia' => 'DOC-BX', 'itens' => [['item_individual_id' => ItemIndividual::value('id')]]])->assertCreated();
    }

    // ---- P-04: níveis de proteção administráveis ----

    public function test_niveis_de_protecao_sao_lista_administravel_e_nome_em_uso_nao_muda(): void
    {
        $this->como($this->c['admin']);
        $id = $this->postJson('/api/v1/reference-lists/niveis-protecao', ['nome' => 'NIJ-IIIA', 'descricao' => 'Nível oficial fictício'])->assertCreated()->json('id');
        $this->getJson('/api/v1/catalog/tipos-material')->assertJsonFragment(['niveis_protecao' => ['II', 'IIA', 'III', 'IIIA', 'IV', 'NIJ-IIIA']]);
        DB::table('itens_individuais')->insert(['material_id' => $this->c['matInd']->id, 'local_estoque_id' => $this->c['localAlfa']->id, 'identificador' => 'N-1',
            'situacao' => 'DISPONIVEL', 'nivel_protecao' => 'NIJ-IIIA', 'created_at' => now(), 'updated_at' => now()]);
        $this->putJson("/api/v1/reference-lists/niveis-protecao/$id", ['nome' => 'Outro'])->assertStatus(409)->assertJsonPath('error.code', 'VALOR_EM_USO');
        $this->putJson("/api/v1/reference-lists/niveis-protecao/$id", ['descricao' => 'Corrigida'])->assertOk();
        $this->como($this->c['armeiro'])->postJson('/api/v1/reference-lists/niveis-protecao', ['nome' => 'X'])->assertForbidden();
    }

    // ---- Importação / exportação ----

    public function test_importacao_simula_confirma_e_desfaz_tudo_se_uma_linha_falha(): void
    {
        $cat = DB::table('categorias')->value('codigo');
        $this->como($this->c['armeiro']);
        $ok = "codigo;nome;categoria_codigo;tipo;modo_controle\nIMP-1;Material importado 1;$cat;;LOTE\nIMP-2;Material importado 2;$cat;;INDIVIDUAL\n";
        $this->post('/api/v1/data-transfer/materiais/import', ['arquivo' => $this->csv($ok), 'simulacao' => 1])->assertOk()
            ->assertJsonPath('validas', 2)->assertJsonPath('gravadas', 0)->assertJsonPath('erros', []);
        $this->assertDatabaseMissing('materiais', ['codigo' => 'IMP-1']);

        $ruim = $ok."IMP-3;X;NAO-EXISTE;;LOTE\nIMP-1;Duplicado;$cat;;LOTE\n";
        $r = $this->post('/api/v1/data-transfer/materiais/import', ['arquivo' => $this->csv($ruim), 'simulacao' => 0])->assertOk()->assertJsonPath('gravadas', 0);
        $this->assertSame([4, 5], collect($r->json('erros'))->pluck('linha')->unique()->values()->all());
        $this->assertSame('categoria_codigo', $r->json('erros.0.campo'));
        $this->assertDatabaseMissing('materiais', ['codigo' => 'IMP-1']); // nada gravado

        $this->post('/api/v1/data-transfer/materiais/import', ['arquivo' => $this->csv($ok), 'simulacao' => 0])->assertOk()->assertJsonPath('gravadas', 2);
        $this->assertDatabaseHas('materiais', ['codigo' => 'IMP-2', 'modo_controle' => 'INDIVIDUAL']);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'importacao.executada']);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'material.criado']);
    }

    public function test_importacao_de_estoque_agrupa_entrada_e_respeita_escopo(): void
    {
        $alfa = DB::table('locais_estoque')->where('id', $this->c['localAlfa']->id)->value('codigo');
        $bravo = DB::table('locais_estoque')->where('id', $this->c['localBravo']->id)->value('codigo');
        $mat = $this->c['matInd']->codigo;
        $lote = $this->c['matLote']->codigo;
        $csv = "local_codigo;documento_referencia;origem;material_codigo;identificador;lote_codigo;quantidade\n"
            ."$alfa;NF-1;Carga inicial;$mat;IMP-A1;;\n$alfa;NF-1;Carga inicial;$mat;IMP-A2;;\n$alfa;NF-1;Carga inicial;$lote;;L-IMP;40\n";
        $this->como($this->c['armeiro'])->post('/api/v1/data-transfer/estoque/import', ['arquivo' => $this->csv($csv), 'simulacao' => 0])->assertOk()->assertJsonPath('gravadas', 3);
        $this->assertSame(1, Movimentacao::where('tipo', 'ENTRADA')->where('documento_referencia', 'NF-1')->count());
        $this->assertSame(2, ItemIndividual::where('identificador', 'like', 'IMP-A%')->count());

        $fora = "local_codigo;documento_referencia;origem;material_codigo;identificador\n$bravo;NF-2;Carga;$mat;IMP-B1\n";
        $this->post('/api/v1/data-transfer/estoque/import', ['arquivo' => $this->csv($fora), 'simulacao' => 0])->assertOk()
            ->assertJsonPath('gravadas', 0)->assertJsonPath('erros.0.linha', 2)
            // fora do escopo responde igual a inexistente (sem revelar que o local existe)
            ->assertJsonPath('erros.0.campo', 'local_codigo')->assertJsonPath('erros.0.mensagem', "\"$bravo\" não encontrado.");
        $this->assertDatabaseMissing('itens_individuais', ['identificador' => 'IMP-B1']);
    }

    public function test_coluna_fora_do_modelo_como_cpf_e_recusada_e_lista_global_exige_admin(): void
    {
        $this->como($this->c['gestorAlfa']);
        $csv = "matricula;nome;nome_guerra;posto_sigla;unidade_sigla;cpf\nIMP-9001;Pessoa;PESSOA;SD;T-ALFA;00000000000\n";
        $this->post('/api/v1/data-transfer/efetivos/import', ['arquivo' => $this->csv($csv), 'simulacao' => 1])->assertStatus(422)->assertJsonPath('error.code', 'COLUNA_DESCONHECIDA');
        $this->post('/api/v1/data-transfer/efetivos/import', ['arquivo' => $this->csv("matricula;nome\nX;Y\n"), 'simulacao' => 1])->assertStatus(422)->assertJsonPath('error.code', 'COLUNA_AUSENTE');

        // Gestor de unidade não administra listas globais: erro por linha, nada gravado
        $this->post('/api/v1/data-transfer/fabricantes/import', ['arquivo' => $this->csv("nome\nFab Importado\n"), 'simulacao' => 0])->assertOk()->assertJsonPath('gravadas', 0);
        $this->assertDatabaseMissing('fabricantes', ['nome' => 'Fab Importado']);
        // a negação fica na trilha apesar do rollback da importação
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'acesso.negado', 'usuario_id' => $this->c['gestorAlfa']->id, 'resultado' => 'NEGADO']);
        $this->como($this->c['admin'])->post('/api/v1/data-transfer/fabricantes/import', ['arquivo' => $this->csv("nome\nFab Importado\n"), 'simulacao' => 0])->assertJsonPath('gravadas', 1);
        // Operador não tem dados.importar
        $this->como($this->c['operadorAlfa1'])->post('/api/v1/data-transfer/fabricantes/import', ['arquivo' => $this->csv("nome\nOutro\n"), 'simulacao' => 1])->assertForbidden();
    }

    public function test_exportacao_de_efetivos_mascara_sem_ver_detalhe_e_modelo_tem_cabecalho(): void
    {
        Militar::factory()->create(['unidade_id' => $this->c['alfa']->id, 'matricula' => 'EXP-12345', 'posto_graduacao_id' => PostoGraduacao::value('id'),
            'email_institucional' => 'sigilo@exemplo.invalid']);
        $this->como($this->c['gestorAlfa']);
        $csv = $this->get('/api/v1/data-transfer/efetivos/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('EXP-12345', $csv);
        $this->assertStringContainsString('sigilo@exemplo.invalid', $csv);

        $this->como($this->c['auditorAlfa']); // relatorios.exportar + militares.ver, sem ver_detalhe
        $csv = $this->get('/api/v1/data-transfer/efetivos/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('EXP-12345', $csv);
        $this->assertStringContainsString('•••345', $csv);
        $this->assertStringNotContainsString('sigilo@', $csv);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'dados.exportados']);

        $modelo = $this->como($this->c['armeiro'])->get('/api/v1/data-transfer/estoque/template')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFlocal_codigo*;documento_referencia*;", $modelo);
        $this->getJson('/api/v1/data-transfer')->assertOk()->assertJsonFragment(['entidade' => 'estoque', 'pode_importar' => true]);
    }
}
