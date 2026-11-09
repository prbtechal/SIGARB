<?php

namespace Tests\Feature;

use App\Models\EventoAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CP-16 — Administrador liga/desliga operações e ajusta políticas pela tela, com motivo e auditoria. */
class ConfiguracoesSistemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    public function test_so_admin_ve_e_altera(): void
    {
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/system-settings')->assertForbidden();
        $this->putJson('/api/v1/system-settings/politicas.bloquear_vencido', ['valor' => false, 'motivo' => 'Tentativa'])->assertForbidden();
        $this->como($this->c['admin'])->getJson('/api/v1/system-settings')->assertOk()->assertJsonFragment(['chave' => 'operacoes.ENTREGA.desligada', 'valor' => false]);
    }

    public function test_desligar_operacao_bloqueia_no_servidor_e_religar_volta(): void
    {
        $this->como($this->c['admin'])->putJson('/api/v1/system-settings/operacoes.ENTRADA.desligada', ['valor' => true, 'motivo' => 'Inventário geral'])->assertOk();
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))
            ->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
        $ev = EventoAuditoria::where('acao', 'configuracao.alterada')->latest('id')->first();
        $this->assertEquals(['chave' => 'operacoes.ENTRADA.desligada', 'antes' => false, 'depois' => true, 'voltou_ao_padrao' => false, 'motivo' => 'Inventário geral'], $ev->dados);

        $this->como($this->c['admin'])->deleteJson('/api/v1/system-settings/operacoes.ENTRADA.desligada', ['motivo' => 'Fim do inventário'])->assertOk();
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->assertCreated();
    }

    public function test_valida_valor_motivo_e_chave(): void
    {
        $this->como($this->c['admin']);
        $this->putJson('/api/v1/system-settings/alertas.validade_dias', ['valor' => 0, 'motivo' => 'Teste inválido'])->assertStatus(422);
        $this->putJson('/api/v1/system-settings/alertas.validade_dias', ['valor' => 60])->assertStatus(422);
        $this->putJson('/api/v1/system-settings/politicas.autoaprovacao_papeis', ['valor' => ['ADMIN'], 'motivo' => 'Teste inválido'])->assertStatus(422);
        $this->putJson('/api/v1/system-settings/senha.min', ['valor' => 4, 'motivo' => 'Teste inválido'])->assertNotFound();
        $this->putJson('/api/v1/system-settings/politicas.autoaprovacao_papeis', ['valor' => ['GESTOR'], 'motivo' => 'Só o gestor'])->assertOk()
            ->assertJsonFragment(['chave' => 'politicas.autoaprovacao_papeis', 'valor' => ['GESTOR'], 'alterado' => true]);
    }
}
