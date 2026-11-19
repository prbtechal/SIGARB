<?php

namespace Tests\Feature;

use App\Models\Documento;
use App\Models\Lote;
use App\Models\Movimentacao;
use App\Models\SaldoEstoque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Arquivos;
use Tests\TestCase;

/**
 * Pedido do responsável (2026-11-19): saída pela tela de saldos, origem por lista + "Outro…", anexo opcional na entrada/saída
 * e tela de Documentos como raiz de busca (anexar a registro sem documento, nova versão do próprio envio).
 */
class SaidaOrigemDocumentosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->cenario();
        Storage::fake('documentos');
    }

    private function mov(array $corpo, ?array $chave = null): TestResponse
    {
        return $this->withHeaders($chave ?? $this->chave())->postJson('/api/v1/movements', $corpo);
    }

    private function saida(int $loteId, int $qtd, array $extra = []): array
    {
        return array_merge(['tipo' => 'SAIDA', 'local_origem_id' => $this->c['localAlfa']->id, 'destinacao' => 'Doação fictícia',
            'documento_referencia' => 'TERMO-S-1', 'justificativa' => 'Saída de teste', 'itens' => [['lote_id' => $loteId, 'quantidade' => $qtd]]], $extra);
    }

    private function saldo(int $localId, int $loteId): int
    {
        return (int) SaldoEstoque::where('local_estoque_id', $localId)->where('lote_id', $loteId)->value('quantidade');
    }

    private function anexar($u, int $movId, $arquivo, array $extra = []): TestResponse
    {
        return $this->como($u)->post("/api/v1/movements/$movId/documents", $extra + ['arquivo' => $arquivo], ['Accept' => 'application/json']);
    }

    public function test_saida_reduz_saldo_bloqueia_saldo_insuficiente_e_exige_motivo(): void
    {
        $this->como($this->c['gestorAlfa']);
        $this->mov($this->entradaLote($this->c['localAlfa'], 50))->assertCreated();
        $lote = Lote::where('codigo', 'L-001')->value('id');

        $id = $this->mov($this->saida($lote, 20, ['destinacao' => '  Doação   fictícia  ']))->assertCreated()->json('id');
        $this->assertSame(30, $this->saldo($this->c['localAlfa']->id, $lote));
        $this->assertSame('Doação fictícia', Movimentacao::find($id)->origem_descricao); // espaços normalizados
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'estoque.saida', 'recurso_id' => $id]);

        $this->mov($this->saida($lote, 31))->assertStatus(409)->assertJsonPath('error.code', 'SALDO_INSUFICIENTE');
        $this->assertSame(30, $this->saldo($this->c['localAlfa']->id, $lote)); // nunca negativo
        $this->mov($this->saida($lote, 1, ['justificativa' => '']))->assertStatus(422);
        $this->mov($this->saida($lote, 1, ['destinacao' => '']))->assertStatus(422);
    }

    public function test_saida_e_idempotente(): void
    {
        $this->como($this->c['gestorAlfa']);
        $this->mov($this->entradaLote($this->c['localAlfa'], 10))->assertCreated();
        $lote = Lote::where('codigo', 'L-001')->value('id');
        $chave = $this->chave();
        $a = $this->mov($this->saida($lote, 4), $chave)->assertCreated()->json('id');
        $this->mov($this->saida($lote, 4), $chave)->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $a);
        $this->assertSame(6, $this->saldo($this->c['localAlfa']->id, $lote));
        $this->assertSame(1, Movimentacao::where('tipo', 'SAIDA')->count());
    }

    public function test_saida_respeita_permissao_e_escopo(): void
    {
        $this->como($this->c['gestorAlfa']);
        $this->mov($this->entradaLote($this->c['localAlfa'], 10))->assertCreated();
        $lote = Lote::where('codigo', 'L-001')->value('id');
        $this->como($this->c['gestorBravo']);
        $this->mov($this->saida($lote, 1))->assertForbidden();
        $this->como($this->c['operadorAlfa1']); // OPERADOR não tem estoque.saida
        $this->mov($this->saida($lote, 1))->assertForbidden();
        $this->assertSame(10, $this->saldo($this->c['localAlfa']->id, $lote));
    }

    public function test_origens_listam_as_usadas_no_escopo_e_outro_grava_o_texto_digitado(): void
    {
        $this->como($this->c['gestorAlfa']);
        $this->mov(['origem_descricao' => 'Doação da SENASP'] + $this->entradaLote($this->c['localAlfa'], 5))->assertCreated();
        $this->mov(['origem_descricao' => 'doação  da senasp'] + $this->entradaLote($this->c['localAlfa'], 5, 'L-002'))->assertCreated();
        // "Outro…": texto novo digitado pelo usuário é gravado como veio (normalizando espaços) e passa a constar na lista.
        $id = $this->mov(['origem_descricao' => ' Apreensão judicial 12/2026 '] + $this->entradaLote($this->c['localAlfa1'], 5, 'L-003'))->assertCreated()->json('id');
        $this->assertSame('Apreensão judicial 12/2026', Movimentacao::find($id)->origem_descricao);

        $lista = $this->getJson('/api/v1/stock/origins')->assertOk()->json('data');
        $this->assertCount(2, $lista); // agrupa sem diferença de maiúsculas
        $this->assertSame(2, $lista[0]['usos']);
        $this->assertSame(['Apreensão judicial 12/2026'], array_column($this->getJson('/api/v1/stock/origins?q=apreensao')->json('data'), 'valor'));

        // Escopo: OPERADOR em ALFA-1 vê só a de ALFA-1; BRAVO não vê nada; sem estoque.entrada = 403.
        $this->como($this->c['operadorAlfa1']);
        $this->assertSame(['Apreensão judicial 12/2026'], array_column($this->getJson('/api/v1/stock/origins')->json('data'), 'valor'));
        $this->como($this->c['gestorBravo']);
        $this->assertSame([], $this->getJson('/api/v1/stock/origins')->json('data'));
        $this->como($this->c['auditor']);
        $this->getJson('/api/v1/stock/origins')->assertForbidden();

        // Destinações da saída (tipo=SAIDA) exigem estoque.saida.
        $this->como($this->c['gestorAlfa']);
        $lote = Lote::where('codigo', 'L-001')->value('id');
        $this->mov($this->saida($lote, 1, ['destinacao' => 'Recolhimento ao DEP']))->assertCreated();
        $this->assertSame(['Recolhimento ao DEP'], array_column($this->getJson('/api/v1/stock/origins?tipo=SAIDA')->json('data'), 'valor'));
        $this->como($this->c['operadorAlfa1']);
        $this->getJson('/api/v1/stock/origins?tipo=SAIDA')->assertForbidden();
    }

    public function test_anexar_documento_a_entrada_sem_anexo_e_achar_na_busca(): void
    {
        $this->como($this->c['gestorAlfa']);
        $id = $this->mov(['origem_descricao' => 'Doação da SENASP', 'documento_referencia' => 'NF-778'] + $this->entradaLote($this->c['localAlfa'], 5))->assertCreated()->json('id');
        $pend = $this->getJson('/api/v1/documents/pending-records')->assertOk()->json('data');
        $this->assertSame([$id], array_column($pend, 'id'));
        $this->assertTrue($pend[0]['pode_anexar']);

        $doc = $this->anexar($this->c['gestorAlfa'], $id, Arquivos::arquivo('nota.pdf', Arquivos::pdf()))->assertCreated()
            ->assertJsonPath('numero_referencia', 'NF-778')->assertJsonPath('unidade.id', $this->c['alfa']->id)
            ->assertJsonPath('tipo.codigo', 'NOTA_RECEBIMENTO')->assertJsonPath('vinculos.0.movimentacao_id', $id)->json('codigo');
        $this->assertSame([], $this->getJson('/api/v1/documents/pending-records')->json('data'));
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.enviado', 'recurso_tipo' => 'documento']);

        // Raiz de busca: origem do registro, material, nº do registro, número do documento e quem enviou.
        foreach (['senasp', $this->c['matLote']->nome, "#$id", 'NF-778', $this->c['gestorAlfa']->nome] as $termo) {
            $r = $this->getJson('/api/v1/documents?'.http_build_query(['q' => $termo]))->assertOk();
            $this->assertSame([$doc], array_column($r->json('data'), 'codigo'), "busca por $termo");
        }
        $r = $this->getJson('/api/v1/documents?registro_tipo=ENTRADA')->assertOk();
        $this->assertSame($id, $r->json('data.0.registros.0.movimentacao_id'));
        $this->assertSame('Doação da SENASP', $r->json('data.0.registros.0.origem'));
        $this->assertSame([], $this->getJson('/api/v1/documents?registro_tipo=SAIDA')->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/documents?q=inexistente')->json('data'));
    }

    public function test_upload_invalido_e_recusado_por_tipo_e_tamanho(): void
    {
        $this->como($this->c['gestorAlfa']);
        $id = $this->mov($this->entradaLote($this->c['localAlfa'], 5))->assertCreated()->json('id');
        $this->anexar($this->c['gestorAlfa'], $id, Arquivos::arquivo('virus.exe', "MZ\x90\x00"))->assertStatus(422)->assertJsonPath('error.code', 'TIPO_NAO_PERMITIDO');
        $this->anexar($this->c['gestorAlfa'], $id, Arquivos::arquivo('falso.pdf', Arquivos::png()))->assertStatus(422)->assertJsonPath('error.code', 'CONTEUDO_INCOMPATIVEL');
        config(['sigarb.documentos.max_bytes' => 2048]);
        $this->anexar($this->c['gestorAlfa'], $id, Arquivos::arquivo('grande.pdf', Arquivos::pdf(str_repeat('A', 5000))))->assertStatus(422);
        $this->assertSame(0, Documento::count());
        $this->assertEmpty(Storage::disk('documentos')->allFiles());
    }

    public function test_anexo_respeita_escopo_e_quem_registrou(): void
    {
        $this->como($this->c['gestorAlfa']);
        $alheio = $this->mov($this->entradaLote($this->c['localAlfa1'], 5))->assertCreated()->json('id');
        // Fora do escopo: 404 (não revela) e auditado.
        $this->anexar($this->c['gestorBravo'], $alheio, Arquivos::arquivo('a.pdf', Arquivos::pdf()))->assertNotFound();
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.anexo_negado', 'recurso_id' => $alheio, 'resultado' => 'NEGADO']);
        // No escopo, mas registro de outra pessoa e sem documentos.gerir: 403.
        $this->anexar($this->c['operadorAlfa1'], $alheio, Arquivos::arquivo('a.pdf', Arquivos::pdf()))->assertForbidden()->assertJsonPath('error.code', 'ANEXO_SO_RESPONSAVEL');
        $this->como($this->c['operadorAlfa1']);
        $this->assertFalse($this->getJson('/api/v1/documents/pending-records')->json('data.0.pode_anexar'));
        // O próprio registro: pode.
        $proprio = $this->mov($this->entradaLote($this->c['localAlfa1'], 5, 'L-009'))->assertCreated()->json('id');
        $this->anexar($this->c['operadorAlfa1'], $proprio, Arquivos::arquivo('b.png', Arquivos::png()))->assertCreated()->assertJsonPath('unidade.id', $this->c['alfa1']->id);
        // Sem documentos.enviar (AUDITOR): a lista de pendentes é 403.
        $this->como($this->c['auditor']);
        $this->getJson('/api/v1/documents/pending-records')->assertForbidden();
    }

    public function test_download_de_anexo_negado_fora_do_escopo(): void
    {
        $this->como($this->c['gestorAlfa']);
        $id = $this->mov($this->entradaLote($this->c['localAlfa'], 5))->assertCreated()->json('id');
        $codigo = $this->anexar($this->c['gestorAlfa'], $id, Arquivos::arquivo('nota.pdf', Arquivos::pdf()))->assertCreated()->json('codigo');
        $this->como($this->c['gestorBravo']);
        $this->get("/api/v1/documents/$codigo/download", ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/documents?q=nota')->json('data'));
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.acesso_negado', 'resultado' => 'NEGADO']);
        $this->como($this->c['gestorAlfa']);
        $this->get("/api/v1/documents/$codigo/download")->assertOk();
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.download', 'resultado' => 'SUCESSO']);
    }

    public function test_nova_versao_so_de_quem_enviou_herda_vinculos_e_lista_mostra_a_atual(): void
    {
        $this->como($this->c['gestorAlfa']);
        $id = $this->mov($this->entradaLote($this->c['localAlfa'], 5))->assertCreated()->json('id');
        $v1 = $this->anexar($this->c['gestorAlfa'], $id, Arquivos::arquivo('nota.pdf', Arquivos::pdf()))->assertCreated()->assertJsonPath('pode_nova_versao', true)->json('codigo');

        $outro = $this->usuarioCom('GESTOR', $this->c['alfa']->id);
        $this->como($outro)->post("/api/v1/documents/$v1/versions", ['arquivo' => Arquivos::arquivo('x.pdf', Arquivos::pdf('% v2'))], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('error.code', 'VERSAO_SO_DE_QUEM_ENVIOU');

        $v2 = $this->como($this->c['gestorAlfa'])->post("/api/v1/documents/$v1/versions", ['arquivo' => Arquivos::arquivo('nota-corrigida.pdf', Arquivos::pdf('% v2'))], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('versao', 2)->assertJsonPath('versao_anterior', $v1)->assertJsonPath('vinculos.0.movimentacao_id', $id)->json('codigo');
        $this->post("/api/v1/documents/$v1/versions", ['arquivo' => Arquivos::arquivo('y.pdf', Arquivos::pdf('% v3'))], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error.code', 'JA_TEM_VERSAO_NOVA');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.nova_versao']);

        $this->assertSame([$v2], array_column($this->getJson('/api/v1/documents')->json('data'), 'codigo'));
        $todas = $this->getJson('/api/v1/documents?versoes=TODAS')->json('data');
        $this->assertCount(2, $todas);
        $this->getJson("/api/v1/documents/$v1")->assertJsonPath('substituido_por', $v2)->assertJsonPath('pode_nova_versao', false);
        // O arquivo da versão 1 continua íntegro e baixável.
        $this->get("/api/v1/documents/$v1/download")->assertOk();
    }
}
