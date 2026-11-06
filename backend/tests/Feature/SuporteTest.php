<?php

namespace Tests\Feature;

use App\Models\Chamado;
use App\Models\ChamadoMensagem;
use App\Models\EventoAuditoria;
use App\Models\Notificacao;
use App\Services\AuditoriaService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuporteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    private function abrir($u, array $extra = []): array
    {
        return $this->como($u)->postJson('/api/v1/support/tickets', $extra + [
            'categoria' => 'ERRO', 'prioridade' => 'MEDIA', 'assunto' => 'Tela de saldos não carrega',
            'descricao' => 'Ao abrir a tela de saldos aparece erro interno.', 'rota' => '/estoque',
        ])->assertCreated()->json();
    }

    public function test_abertura_gera_protocolo_codigo_opaco_mensagem_e_auditoria(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        $this->assertMatchesRegularExpression('/^SUP-\d{8}-[A-Z0-9]{6}$/', $c['protocolo']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $c['codigo']);
        $this->assertArrayNotHasKey('id', $c);
        $this->assertSame('ABERTO', $c['status']);
        $this->assertCount(1, $c['mensagens']);
        $this->assertNull($c['diagnostico']); // solicitante não vê diagnóstico
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'suporte.chamado.aberto', 'recurso_tipo' => 'chamado']);
        $this->assertSame($this->c['alfa']->id, Chamado::first()->unidade_id);
    }

    public function test_diagnostico_seguro_sem_query_string_nem_payload(): void
    {
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/support/tickets', [
            'categoria' => 'ERRO', 'prioridade' => 'BAIXA', 'assunto' => 'Assunto válido', 'descricao' => 'Descrição válida aqui.',
            'rota' => '/estoque?token=abc',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rota']]]);
        $this->abrir($this->c['gestorAlfa']);
        $diag = Chamado::first()->diagnostico;
        $this->assertSame(['rota', 'navegador', 'request_id'], array_values(array_intersect(['rota', 'navegador', 'request_id'], array_keys($diag))));
        $this->assertSame('/estoque', $diag['rota']);
    }

    public function test_redacao_de_segredos_e_cpf_antes_de_gravar(): void
    {
        $c = $this->abrir($this->c['gestorAlfa'], ['descricao' => 'Minha senha: Abc12345! e o token=eyJabcdef.ghijklm.nopqrst, CPF 123.456.789-09, Bearer abc.def']);
        $corpo = ChamadoMensagem::first()->corpo;
        foreach (['Abc12345!', 'eyJabcdef', '123.456.789-09', 'abc.def'] as $segredo) {
            $this->assertStringNotContainsString($segredo, $corpo);
        }
        $this->assertTrue(ChamadoMensagem::first()->redigido);
        $this->assertTrue($c['mensagens'][0]['redigido']);
        // A auditoria nunca guarda o texto livre.
        $this->assertStringNotContainsString('senha', json_encode(EventoAuditoria::where('acao', 'like', 'suporte.%')->pluck('dados')));
    }

    public function test_isolamento_entre_usuarios_e_404_sem_enumeracao(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        foreach (['gestorBravo', 'auditor', 'operadorAlfa1'] as $outro) {
            if (! isset($this->c[$outro])) {
                continue;
            }
            $this->como($this->c[$outro])->getJson("/api/v1/support/tickets/{$c['codigo']}")->assertNotFound();
            $this->como($this->c[$outro])->postJson("/api/v1/support/tickets/{$c['codigo']}/messages", ['corpo' => 'intrusão'])->assertNotFound();
        }
        $this->como($this->c['gestorBravo'])->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(0, 'data');
        // Código inexistente e código alheio respondem igual.
        $this->como($this->c['gestorBravo'])->getJson('/api/v1/support/tickets/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
        $this->como($this->c['gestorBravo'])->getJson('/api/v1/support/queue')->assertForbidden();
    }

    public function test_atendimento_restrito_ao_escopo_de_unidade(): void
    {
        $cAlfa = $this->abrir($this->c['gestorAlfa']);
        $cBravo = $this->abrir($this->c['gestorBravo']);
        $adminAlfa = $this->usuarioCom('ADMIN', $this->c['alfa']->id);
        $this->como($adminAlfa)->getJson('/api/v1/support/queue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.codigo', $cAlfa['codigo']);
        $this->como($adminAlfa)->getJson("/api/v1/support/tickets/{$cBravo['codigo']}")->assertNotFound();
        $this->como($this->c['admin'])->getJson('/api/v1/support/queue')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_permissao_de_suporte_nao_concede_dados_organizacionais(): void
    {
        $this->abrir($this->c['gestorAlfa']);
        // ADMIN atende o suporte, mas não tem estoque.ver nem documentos.ver.
        $this->como($this->c['admin'])->getJson('/api/v1/stock/balances')->assertForbidden();
        $this->como($this->c['admin'])->getJson('/api/v1/documents')->assertForbidden();
    }

    public function test_nota_interna_invisivel_ao_solicitante_e_exclusiva_do_atendimento(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/support/tickets/{$c['codigo']}/messages", ['corpo' => 'tentativa', 'tipo' => 'INTERNA'])->assertForbidden();
        $this->como($this->c['admin'])->postJson("/api/v1/support/tickets/{$c['codigo']}/messages", ['corpo' => 'Verificar logs do servidor', 'tipo' => 'INTERNA'])->assertCreated();
        $this->como($this->c['admin'])->postJson("/api/v1/support/tickets/{$c['codigo']}/messages", ['corpo' => 'Pode limpar o cache do navegador?'])->assertCreated();
        $visto = $this->como($this->c['gestorAlfa'])->getJson("/api/v1/support/tickets/{$c['codigo']}")->assertOk()->json('mensagens');
        $this->assertSame(['PUBLICA', 'PUBLICA'], array_column($visto, 'tipo'));
        $this->assertStringNotContainsString('Verificar logs', json_encode($visto));
        $adm = $this->como($this->c['admin'])->getJson("/api/v1/support/tickets/{$c['codigo']}")->json('mensagens');
        $this->assertContains('INTERNA', array_column($adm, 'tipo'));
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'suporte.nota_interna']);
    }

    public function test_atribuicao_transicoes_e_notificacoes(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        $adm = $this->c['admin'];
        // Responsável sem permissão de atendimento é recusado.
        $this->como($adm)->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 1, 'atribuido_a_id' => $this->c['gestorAlfa']->id])->assertStatus(422);
        $r = $this->como($adm)->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 1, 'atribuido_a_id' => $adm->id, 'status' => 'EM_ATENDIMENTO', 'prioridade' => 'ALTA'])->assertOk();
        $this->assertSame(['EM_ATENDIMENTO', 'ALTA', 2], [$r->json('status'), $r->json('prioridade'), $r->json('versao')]);
        // Transição inválida.
        $this->como($adm)->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 2, 'status' => 'ABERTO'])->assertStatus(422)->assertJsonPath('error.code', 'TRANSICAO_INVALIDA');
        // Solicitante recebe notificação da mudança de situação, sem conteúdo do chamado.
        $n = $this->como($this->c['gestorAlfa'])->getJson('/api/v1/notifications')->assertOk();
        $this->assertSame(1, $n->json('nao_lidas'));
        $this->assertStringContainsString($c['protocolo'], $n->json('data.0.texto'));
        // Solicitante não pode alterar status.
        $this->como($this->c['gestorAlfa'])->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 2, 'status' => 'RESOLVIDO'])->assertForbidden();
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'suporte.atendimento.alterado']);
        $this->assertDatabaseHas('chamado_mensagens', ['tipo' => 'SISTEMA']);
    }

    public function test_conflito_de_versao_em_alteracoes_concorrentes(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        $adm = $this->c['admin'];
        $this->como($adm)->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 1, 'prioridade' => 'ALTA'])->assertOk();
        // Segunda alteração com a versão antiga (outro atendente com a tela desatualizada).
        $this->como($adm)->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 1, 'prioridade' => 'BAIXA'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CONFLITO_VERSAO');
        $this->assertSame('ALTA', Chamado::first()->prioridade);
    }

    public function test_resposta_do_solicitante_retoma_atendimento_e_notifica_responsavel(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        $adm = $this->c['admin'];
        $this->como($adm)->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 1, 'atribuido_a_id' => $adm->id, 'status' => 'AGUARDANDO_SOLICITANTE'])->assertOk();
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/support/tickets/{$c['codigo']}/messages", ['corpo' => 'Já limpei o cache.'])->assertCreated()
            ->assertJsonPath('status', 'EM_ATENDIMENTO');
        $this->assertSame(1, Notificacao::where('usuario_id', $adm->id)->whereNull('lida_em')->count());
    }

    public function test_resolver_e_reabrir_com_prazo_e_limite(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        $adm = $this->c['admin'];
        $this->como($adm)->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 1, 'status' => 'RESOLVIDO'])->assertOk()->assertJsonPath('pode_reabrir', true);
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/support/tickets/{$c['codigo']}/messages", ['corpo' => 'depois de resolvido'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CHAMADO_ENCERRADO');
        $r = $this->como($this->c['gestorAlfa'])->postJson("/api/v1/support/tickets/{$c['codigo']}/reopen", ['versao' => 2, 'motivo' => 'O erro voltou hoje.'])->assertOk();
        $this->assertSame(['ABERTO', 1], [$r->json('status'), $r->json('reaberturas')]);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'suporte.chamado.reaberto']);
        // Prazo vencido: solicitante não reabre; atendimento pode.
        $this->como($adm)->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 3, 'status' => 'RESOLVIDO'])->assertOk();
        DB::table('chamados')->update(['resolvido_em' => now()->subDays(20)]);
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/support/tickets/{$c['codigo']}/reopen", ['versao' => 4, 'motivo' => 'Voltou de novo.'])
            ->assertStatus(422)->assertJsonPath('error.code', 'PRAZO_REABERTURA');
        $this->como($adm)->postJson("/api/v1/support/tickets/{$c['codigo']}/reopen", ['versao' => 4, 'motivo' => 'Reaberto pelo atendimento.'])->assertOk();
    }

    public function test_historico_de_auditoria_do_chamado_somente_atendimento(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        $this->como($this->c['gestorAlfa'])->getJson("/api/v1/support/tickets/{$c['codigo']}/history")->assertForbidden();
        $h = $this->como($this->c['admin'])->getJson("/api/v1/support/tickets/{$c['codigo']}/history")->assertOk()->json('data');
        $this->assertSame('suporte.chamado.aberto', $h[0]['acao']);
        $this->assertNotEmpty($h[0]['request_id']);
        // Filtro por request_id no console de auditoria.
        $this->como($this->c['auditor'])->getJson('/api/v1/audit-events?request_id='.$h[0]['request_id'])->assertOk()->assertJsonCount(1, 'data');
        $this->assertNull(app(AuditoriaService::class)->verificar());
    }

    public function test_mensagens_e_chamados_sao_imutaveis_no_banco(): void
    {
        $this->abrir($this->c['gestorAlfa']);
        foreach (['UPDATE chamado_mensagens SET corpo = ?' => ['x'], 'DELETE FROM chamado_mensagens' => [], 'DELETE FROM chamados' => [],
            'UPDATE chamados SET solicitante_id = ?' => [$this->c['gestorBravo']->id]] as $sql => $b) {
            try {
                DB::statement($sql, $b);
                $this->fail("Deveria bloquear: $sql");
            } catch (QueryException $e) {
                $this->assertStringContainsString('45000', $e->getMessage());
            }
        }
    }

    public function test_notificacoes_isoladas_por_usuario(): void
    {
        $c = $this->abrir($this->c['gestorAlfa']);
        $this->como($this->c['admin'])->patchJson("/api/v1/support/tickets/{$c['codigo']}", ['versao' => 1, 'status' => 'EM_ATENDIMENTO'])->assertOk();
        $id = Notificacao::first()->id;
        $this->como($this->c['gestorBravo'])->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
        $this->como($this->c['gestorAlfa'])->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->assertNotNull(Notificacao::first()->lida_em);
    }

    public function test_limite_de_taxa_na_abertura(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->abrir($this->c['gestorAlfa']);
        }
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/support/tickets', [
            'categoria' => 'ERRO', 'prioridade' => 'MEDIA', 'assunto' => 'Sexto chamado', 'descricao' => 'Mais um chamado em sequência.',
        ])->assertStatus(429);
    }
}
