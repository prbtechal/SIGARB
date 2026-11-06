<?php

namespace Tests\Feature;

use App\Models\Documento;
use App\Models\EventoAuditoria;
use App\Models\TipoDocumento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Arquivos;
use Tests\TestCase;

/** RF-014, RN-019, RN-021, RN-020, RNF-03, RNF-04. */
class DocumentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        Storage::fake('documentos');
    }

    private function dados(array $extra = []): array
    {
        return array_merge(['tipo_documento_id' => TipoDocumento::where('codigo', 'TERMO')->value('id'), 'unidade_id' => $this->c['alfa']->id, 'titulo' => 'Termo fictício de teste'], $extra);
    }

    private function enviar($u, $arquivo, array $extra = [])
    {
        return $this->como($u)->post('/api/v1/documents', $this->dados($extra) + ['arquivo' => $arquivo], ['Accept' => 'application/json']);
    }

    private function docAlfa(): Documento
    {
        $codigo = $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('termo.pdf', Arquivos::pdf()))->assertCreated()->json('codigo');

        return Documento::where('codigo_publico', $codigo)->firstOrFail();
    }

    public function test_upload_valido_grava_privado_com_checksum_e_audita_sem_expor_caminho(): void
    {
        $conteudo = Arquivos::pdf();
        $r = $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('Termo de Teste.pdf', $conteudo))->assertCreated()
            ->assertJsonPath('mime', 'application/pdf')->assertJsonPath('nome_original', 'Termo de Teste.pdf')
            ->assertJsonPath('sha256', hash('sha256', $conteudo))->assertJsonPath('situacao', 'ATIVO');
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $r->json('codigo'));
        $doc = Documento::where('codigo_publico', $r->json('codigo'))->first();
        Storage::disk('documentos')->assertExists($doc->caminho());
        $this->assertSame($conteudo, Storage::disk('documentos')->get($doc->caminho()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $doc->armazenamento_id);
        $json = $r->getContent();
        $this->assertStringNotContainsString($doc->armazenamento_id, $json);
        $this->assertStringNotContainsString('storage', $json);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.enviado', 'recurso_id' => $doc->id, 'unidade_id' => $this->c['alfa']->id]);
        $this->assertStringNotContainsString('%PDF', json_encode(EventoAuditoria::pluck('dados')));
    }

    public function test_png_e_jpg_sao_aceitos(): void
    {
        $this->enviar($this->c['operadorAlfa1'], Arquivos::arquivo('foto.png', Arquivos::png()), ['unidade_id' => $this->c['alfa1']->id])->assertCreated()->assertJsonPath('mime', 'image/png');
    }

    public function test_formatos_fora_da_lista_sao_rejeitados(): void
    {
        foreach (['virus.exe' => "MZ\x90\x00", 'pagina.html' => '<html><script>alert(1)</script></html>', 'imagem.svg' => '<svg onload="alert(1)"/>', 'planilha.docx' => "PK\x03\x04", 'sem_extensao' => Arquivos::pdf()] as $nome => $c) {
            $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo($nome, $c))->assertStatus(422)->assertJsonPath('error.code', 'TIPO_NAO_PERMITIDO');
        }
        $this->assertSame(0, Documento::count());
        $this->assertEmpty(Storage::disk('documentos')->allFiles());
    }

    public function test_conteudo_que_nao_corresponde_a_extensao_e_rejeitado(): void
    {
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('falso.pdf', Arquivos::png()))->assertStatus(422)->assertJsonPath('error.code', 'CONTEUDO_INCOMPATIVEL');
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('falso.png', '<html>oi</html>'))->assertStatus(422)->assertJsonPath('error.code', 'CONTEUDO_INCOMPATIVEL');
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('falso.jpg', Arquivos::pdf()))->assertStatus(422)->assertJsonPath('error.code', 'CONTEUDO_INCOMPATIVEL');
    }

    public function test_pdf_malformado_e_pdf_com_conteudo_ativo_sao_rejeitados(): void
    {
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('truncado.pdf', "%PDF-1.4\n1 0 obj<<>>"))->assertStatus(422)->assertJsonPath('error.code', 'CONTEUDO_MALFORMADO');
        foreach (['/JavaScript (app.alert(1))', '/OpenAction 3 0 R', '/Launch /F (cmd.exe)', '/EmbeddedFile', '/JS (x)'] as $ativo) {
            $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('ativo.pdf', Arquivos::pdf("3 0 obj<<$ativo>>endobj")))->assertStatus(422)->assertJsonPath('error.code', 'CONTEUDO_ATIVO');
        }
        $this->assertSame(0, Documento::count());
    }

    public function test_pdf_com_nomes_codificados_ou_object_streams_e_recusado_por_nao_ser_verificavel(): void
    {
        foreach (['3 0 obj<</J#61vaScript (app.alert(1))>>endobj', '3 0 obj<</S /J#53>>endobj', '4 0 obj<</Type /ObjStm /N 1 /First 4 /Filter /FlateDecode>>stream\nx\nendstream endobj'] as $evasivo) {
            $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('evasivo.pdf', Arquivos::pdf($evasivo)))
                ->assertStatus(422)->assertJsonPath('error.code', 'CONTEUDO_NAO_VERIFICAVEL');
        }
        // PDF simples com texto contendo '#' fora de nomes continua aceito
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('ok.pdf', Arquivos::pdf('5 0 obj<</Length 10>>stream\n(Item #12)\nendstream endobj')))->assertCreated();
    }

    public function test_arquivo_vazio_e_arquivo_grande_demais_sao_rejeitados(): void
    {
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('vazio.pdf', ''))->assertStatus(422);
        config(['sigarb.documentos.max_bytes' => 1024]);
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('grande.pdf', Arquivos::pdf(str_repeat('%x', 2000))))->assertStatus(422);
        $this->assertSame(0, Documento::count());
    }

    public function test_nome_com_path_traversal_e_saneado_e_nao_influencia_o_armazenamento(): void
    {
        foreach (['../../../../etc/passwd.pdf', '..\\..\\windows\\win.ini.pdf', "nome\x00oculto\r\nX-Header: y.pdf", '.htaccess.pdf'] as $nome) {
            $r = $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo($nome, Arquivos::pdf()))->assertCreated();
            $exib = $r->json('nome_original');
            $this->assertStringNotContainsString('/', $exib);
            $this->assertStringNotContainsString('\\', $exib);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $exib);
            $this->assertStringStartsNotWith('.', $exib);
            $this->assertStringEndsWith('.pdf', $exib);
        }
        foreach (Storage::disk('documentos')->allFiles() as $f) {
            $this->assertMatchesRegularExpression('#^documentos/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{64}$#', $f);
        }
    }

    public function test_mesmo_nome_e_conteudo_geram_documentos_distintos_sem_sobrescrever(): void
    {
        $a = $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('igual.pdf', Arquivos::pdf('% A')))->json('codigo');
        $b = $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('igual.pdf', Arquivos::pdf('% B')))->json('codigo');
        $da = Documento::where('codigo_publico', $a)->first();
        $db = Documento::where('codigo_publico', $b)->first();
        $this->assertNotSame($da->armazenamento_id, $db->armazenamento_id);
        $this->assertStringContainsString('% A', Storage::disk('documentos')->get($da->caminho()));
        $this->assertStringContainsString('% B', Storage::disk('documentos')->get($db->caminho()));
    }

    public function test_upload_sem_permissao_ou_fora_do_escopo_e_negado(): void
    {
        $arq = fn () => Arquivos::arquivo('t.pdf', Arquivos::pdf());
        $this->enviar($this->c['auditor'], $arq())->assertForbidden();
        $this->enviar($this->c['admin'], $arq())->assertForbidden();            // administrador não tem acesso irrestrito a documentos
        $this->enviar($this->c['aprovador'], $arq())->assertForbidden();
        $this->enviar($this->c['gestorBravo'], $arq())->assertForbidden();      // unidade ALFA fora do escopo
        $this->enviar($this->c['operadorAlfa1'], $arq())->assertForbidden();    // ALFA é acima de ALFA-1
        $this->assertSame(0, Documento::count());
        $this->assertEmpty(Storage::disk('documentos')->allFiles());
        $this->assertGreaterThanOrEqual(5, EventoAuditoria::where('acao', 'acesso.negado')->count());
    }

    public function test_outra_unidade_nao_consulta_nem_baixa_e_adivinhar_codigo_nao_ajuda(): void
    {
        $doc = $this->docAlfa();
        $this->como($this->c['gestorBravo']);
        foreach (["/api/v1/documents/{$doc->codigo_publico}", "/api/v1/documents/{$doc->codigo_publico}/download"] as $url) {
            $this->getJson($url)->assertNotFound()->assertJsonPath('error.code', 'NAO_ENCONTRADO');
        }
        $inexistente = $this->getJson('/api/v1/documents/01ZZZZZZZZZZZZZZZZZZZZZZZZ/download')->assertNotFound()->json();
        $this->assertSame($inexistente['error']['code'], 'NAO_ENCONTRADO'); // mesma resposta: não revela existência
        $this->getJson("/api/v1/documents/{$doc->id}")->assertNotFound();              // ID interno não serve
        $this->getJson('/api/v1/documents/..%2F..%2F.env/download')->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/documents')->json('meta.total'));
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.acesso_negado', 'recurso_id' => $doc->id, 'usuario_id' => $this->c['gestorBravo']->id]);
        $this->assertDatabaseMissing('eventos_auditoria', ['acao' => 'documento.download']);
    }

    public function test_hierarquia_admin_e_auditor(): void
    {
        $doc = $this->docAlfa();
        $this->como($this->c['operadorAlfa1'])->getJson("/api/v1/documents/{$doc->codigo_publico}")->assertNotFound(); // subunidade não vê a superior
        $this->como($this->c['admin'])->getJson("/api/v1/documents/{$doc->codigo_publico}/download")->assertNotFound();
        $this->getJson('/api/v1/documents')->assertForbidden();
        $this->como($this->c['auditorAlfa'])->get("/api/v1/documents/{$doc->codigo_publico}/download")->assertOk();
        $this->como($this->c['auditorAlfa'])->putJson("/api/v1/documents/{$doc->codigo_publico}", ['titulo' => 'Auditor tentando editar'])
            ->assertForbidden(); // enxerga, mas não gere → 403
    }

    public function test_download_autorizado_tem_cabecalhos_seguros_e_e_auditado(): void
    {
        $doc = $this->docAlfa();
        $r = $this->como($this->c['gestorAlfa'])->get("/api/v1/documents/{$doc->codigo_publico}/download")->assertOk();
        $this->assertStringStartsWith('attachment;', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('termo.pdf', $r->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $r->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertSame(Arquivos::pdf(), $r->streamedContent());
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.download', 'recurso_id' => $doc->id, 'usuario_id' => $this->c['gestorAlfa']->id]);
        $this->getJson("/api/v1/documents/{$doc->codigo_publico}")->assertOk()->assertJsonMissingPath('armazenamento_id');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.consulta', 'recurso_id' => $doc->id]);
    }

    public function test_conta_desativada_nao_baixa(): void
    {
        $doc = $this->docAlfa();
        $g = $this->c['gestorAlfa'];
        $this->como($this->c['admin'])->putJson("/api/v1/users/{$g->id}", ['ativo' => false])->assertOk();
        $this->como($g->fresh())->get("/api/v1/documents/{$doc->codigo_publico}/download", ['Accept' => 'application/json'])->assertStatus(401)->assertJsonPath('error.code', 'CONTA_INATIVA');
    }

    public function test_arquivo_adulterado_no_disco_bloqueia_download_e_audita_falha(): void
    {
        $doc = $this->docAlfa();
        Storage::disk('documentos')->put($doc->caminho(), Arquivos::pdf('% adulterado'));
        $this->como($this->c['gestorAlfa'])->getJson("/api/v1/documents/{$doc->codigo_publico}/download")->assertStatus(409)->assertJsonPath('error.code', 'INTEGRIDADE_VIOLADA');
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.integridade', 'resultado' => 'FALHA', 'recurso_id' => $doc->id]);
        Storage::disk('documentos')->delete($doc->caminho());
        $this->getJson("/api/v1/documents/{$doc->codigo_publico}/download")->assertStatus(409)->assertJsonPath('error.code', 'ARQUIVO_INDISPONIVEL');
    }

    public function test_metadados_validados_restritos_e_auditados(): void
    {
        $doc = $this->docAlfa();
        $url = "/api/v1/documents/{$doc->codigo_publico}";
        $this->como($this->c['operadorAlfa1'])->putJson($url, ['titulo' => 'Operador de outra unidade'])->assertNotFound();
        $this->como($this->c['gestorAlfa'])->putJson($url, ['titulo' => 'x'])->assertStatus(422);
        $this->putJson($url, ['unidade_id' => $this->c['bravo']->id])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['unidade_id']]]);
        $this->putJson($url, ['sha256' => str_repeat('0', 64)])->assertStatus(422);
        $this->putJson($url, ['titulo' => 'Título corrigido', 'numero_referencia' => 'REF-9'])->assertOk()->assertJsonPath('titulo', 'Título corrigido');
        $ev = EventoAuditoria::where('acao', 'documento.metadados')->first();
        $this->assertSame('Termo fictício de teste', $ev->dados['antes']['titulo']);
        $this->assertSame('Título corrigido', $ev->dados['depois']['titulo']);
    }

    public function test_ciclo_de_vida_arquivar_reativar_com_justificativa(): void
    {
        $doc = $this->docAlfa();
        $url = "/api/v1/documents/{$doc->codigo_publico}";
        $this->como($this->c['gestorAlfa'])->postJson("$url/archive", ['justificativa' => 'curta'])->assertStatus(422);
        $this->postJson("$url/archive", ['justificativa' => 'Substituído por versão mais recente'])->assertOk()->assertJsonPath('situacao', 'ARQUIVADO');
        $this->postJson("$url/archive", ['justificativa' => 'Substituído por versão mais recente'])->assertStatus(409);
        $this->putJson($url, ['titulo' => 'Editar arquivado'])->assertStatus(409)->assertJsonPath('error.code', 'DOCUMENTO_ARQUIVADO');
        $this->get("$url/download")->assertOk(); // arquivado continua consultável por quem tem escopo
        $this->postJson("$url/reactivate", ['justificativa' => 'Arquivamento feito por engano'])->assertOk()->assertJsonPath('situacao', 'ATIVO');
        $this->getJson('/api/v1/documents?situacao=ARQUIVADO')->assertJsonPath('meta.total', 0);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.arquivado']);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'documento.reativado']);
    }

    public function test_banco_impede_trocar_arquivo_apagar_documento_ou_vinculo(): void
    {
        $doc = $this->docAlfa();
        $falhas = 0;
        foreach ([
            fn () => DB::update("UPDATE documentos SET sha256 = REPEAT('0',64)"),
            fn () => DB::update("UPDATE documentos SET armazenamento_id = REPEAT('a',64)"),
            fn () => DB::update('UPDATE documentos SET unidade_id = ?', [$this->c['bravo']->id]),
            fn () => DB::delete('DELETE FROM documentos'),
            fn () => DB::update("UPDATE eventos_auditoria SET acao = 'apagado'"),
        ] as $t) {
            try {
                DB::transaction($t);
            } catch (QueryException) {
                $falhas++;
            }
        }
        $this->assertSame(5, $falhas);
        $this->assertSame(hash('sha256', Arquivos::pdf()), $doc->fresh()->sha256);
    }

    public function test_vinculo_com_movimentacao_respeita_escopo_e_e_unico(): void
    {
        $movAlfa = $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->json('id');
        $movBravo = $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localBravo'], 5, 'LB'))->json('id');
        $doc = $this->docAlfa();
        $url = "/api/v1/documents/{$doc->codigo_publico}/links";
        $this->como($this->c['gestorAlfa'])->postJson($url, ['movimentacao_id' => $movBravo])->assertStatus(422)->assertJsonPath('error.code', 'MOVIMENTACAO_INACESSIVEL');
        $this->postJson($url, ['movimentacao_id' => $movAlfa])->assertCreated()->assertJsonPath('vinculos.0.movimentacao_id', $movAlfa);
        $this->postJson($url, ['movimentacao_id' => $movAlfa])->assertStatus(422)->assertJsonPath('error.code', 'VINCULO_DUPLICADO');
        $this->getJson("/api/v1/documents?movimentacao_id=$movAlfa")->assertJsonPath('meta.total', 1);
        // vínculo no próprio upload
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('nota.pdf', Arquivos::pdf()), ['movimentacao_id' => $movBravo])->assertStatus(422);
        $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('nota.pdf', Arquivos::pdf()), ['movimentacao_id' => $movAlfa])->assertCreated()->assertJsonCount(1, 'vinculos');
    }

    public function test_listagem_busca_filtros_paginacao_e_escopo(): void
    {
        foreach (['Ofício alfa um', 'Ofício alfa dois', 'Relatório alfa'] as $t) {
            $this->enviar($this->c['gestorAlfa'], Arquivos::arquivo('x.pdf', Arquivos::pdf()), ['titulo' => $t])->assertCreated();
        }
        $this->enviar($this->c['gestorBravo'], Arquivos::arquivo('b.pdf', Arquivos::pdf()), ['unidade_id' => $this->c['bravo']->id, 'titulo' => 'Ofício bravo'])->assertCreated();
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/documents?q=Ofício&per_page=1')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->como($this->c['auditor'])->getJson('/api/v1/documents?q=Ofício')->assertJsonPath('meta.total', 3);
        $r = $this->getJson('/api/v1/documents?q=%25')->assertOk();
        $this->assertSame(0, $r->json('meta.total'));
        $this->assertStringNotContainsString('armazenamento', $this->getJson('/api/v1/documents')->getContent());
        $this->getJson('/api/v1/documents?per_page=500')->assertStatus(422);
    }

    public function test_operacoes_institucionais_continuam_bloqueadas_via_api(): void
    {
        $this->como($this->c['gestorAlfa']);
        foreach (['/api/v1/acautelamentos/requests', '/api/v1/acautelamentos/1/decision', '/api/v1/transfers'] as $u) {
            $this->postJson($u, [])->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
        }
        $this->getJson('/api/v1/reports/carteira')->assertStatus(409);
    }
}
