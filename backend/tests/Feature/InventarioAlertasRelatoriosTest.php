<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RelatorioController;
use App\Models\Categoria;
use App\Models\Lote;
use App\Models\SaldoEstoque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** RF-020 (inventário), RF-022 (alertas), RF-023 (exportação CSV), RF-007 (ficha técnica) — regras PROVISÓRIAS. */
class InventarioAlertasRelatoriosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
        config(['sigarb.operacoes_provisorias' => ['INVENTARIO', 'RESERVA']]);
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 100, 'L-INV', now()->addDays(10)->toDateString()))->assertCreated();
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaIndividual($this->c['localAlfa'], 'PAT-INV'))->assertCreated();
    }

    private function saldo(): int
    {
        return (int) SaldoEstoque::where('lote_id', Lote::where('codigo', 'L-INV')->value('id'))->value('quantidade');
    }

    private function abrirEContar(int $contadoLote, int $contadoItem): array
    {
        $inv = $this->como($this->c['gestorAlfa'])->postJson('/api/v1/inventories', ['local_estoque_id' => $this->c['localAlfa']->id])->assertCreated()->json();
        $this->postJson('/api/v1/inventories', ['local_estoque_id' => $this->c['localAlfa']->id])->assertStatus(409)->assertJsonPath('error.code', 'INVENTARIO_EM_CURSO');
        $cont = collect($inv['itens'])->map(fn ($l) => ['id' => $l['id'], 'contado' => $l['tipo'] === 'LOTE' ? $contadoLote : $contadoItem])->all();
        $this->postJson("/api/v1/inventories/{$inv['codigo']}/submit", ['versao' => 1])->assertStatus(422)->assertJsonPath('error.code', 'CONTAGEM_INCOMPLETA');
        $this->putJson("/api/v1/inventories/{$inv['codigo']}/counts", ['versao' => 1, 'contagens' => $cont])->assertOk();
        $this->postJson("/api/v1/inventories/{$inv['codigo']}/submit", ['versao' => 2])->assertOk()->assertJsonPath('estado', 'AGUARDANDO_APROVACAO')->assertJsonPath('divergencias', 2);

        return $inv;
    }

    public function test_inventario_ajusta_lote_com_aprovacao_segregada(): void
    {
        $inv = $this->abrirEContar(93, 0);
        $this->postJson("/api/v1/inventories/{$inv['codigo']}/decision", ['versao' => 3, 'decisao' => 'APROVAR'])->assertForbidden();
        $this->como($this->c['aprovador'])->getJson("/api/v1/inventories/{$inv['codigo']}")->assertJsonPath('acoes.decidir', true);
        $this->postJson("/api/v1/inventories/{$inv['codigo']}/decision", ['versao' => 3, 'decisao' => 'APROVAR'])->assertOk()->assertJsonPath('estado', 'AJUSTADO');
        $this->assertSame(93, $this->saldo());
        $this->assertDatabaseHas('movimentacoes', ['tipo' => 'AJUSTE', 'local_origem_id' => $this->c['localAlfa']->id, 'provisoria' => true]);
        $this->assertDatabaseHas('itens_individuais', ['identificador' => 'PAT-INV', 'situacao' => 'DISPONIVEL']); // individual só registrado
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'inventario.ajuste_aprovado']);
    }

    public function test_inventario_desatualizado_e_bloqueio_padrao(): void
    {
        $inv = $this->abrirEContar(105, 0);
        $this->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 1, 'L-INV'))->assertCreated();
        $this->como($this->c['aprovador'])->postJson("/api/v1/inventories/{$inv['codigo']}/decision", ['versao' => 3, 'decisao' => 'APROVAR'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVENTARIO_DESATUALIZADO');
        $this->assertSame(101, $this->saldo());
        $this->postJson("/api/v1/inventories/{$inv['codigo']}/decision", ['versao' => 3, 'decisao' => 'REJEITAR', 'observacao' => 'Recontar'])->assertJsonPath('estado', 'REJEITADO');
        $this->como($this->c['gestorBravo'])->getJson("/api/v1/inventories/{$inv['codigo']}")->assertNotFound();
        config(['sigarb.operacoes_provisorias' => []]);
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/inventories', ['local_estoque_id' => $this->c['localAlfa']->id])->assertStatus(409)->assertJsonPath('error.code', 'OPERACAO_BLOQUEADA');
    }

    public function test_alertas_no_escopo(): void
    {
        $tipos = collect($this->como($this->c['gestorAlfa'])->getJson('/api/v1/alerts')->assertOk()->json('data'))->pluck('quantidade', 'tipo');
        $this->assertSame(1, $tipos['LOTE_VENCENDO']);
        $this->assertEmpty($this->como($this->c['gestorBravo'])->getJson('/api/v1/alerts')->json('data'));
    }

    public function test_exportacao_csv_escopada_auditada_e_sem_injecao(): void
    {
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/reports')->assertJsonCount(3, 'data');
        $csv = $this->get('/api/v1/reports/saldos')->assertOk()->streamedContent();
        $this->assertStringContainsString('L-INV', $csv);
        $this->assertDatabaseHas('eventos_auditoria', ['acao' => 'relatorio.exportado']);
        $this->assertStringNotContainsString('L-INV', $this->como($this->c['gestorBravo'])->get('/api/v1/reports/saldos')->streamedContent());
        $this->como($this->c['aprovador'])->get('/api/v1/reports/saldos')->assertForbidden();
        $this->assertSame("'=1+1", RelatorioController::celula('=1+1'));
        $this->assertSame("'-2", RelatorioController::celula('-2'));
    }

    public function test_ficha_tecnica_so_para_armamento(): void
    {
        $arm = Categoria::firstOrCreate(['codigo' => 'ARM-T'], ['nome' => 'Armamento teste', 'natureza' => 'ARMAMENTO']);
        $mun = Categoria::firstOrCreate(['codigo' => 'MUN-T'], ['nome' => 'Munição teste', 'natureza' => 'MUNICAO']);
        $base = ['codigo' => 'PST-T1', 'nome' => 'Pistola teste', 'modo_controle' => 'INDIVIDUAL', 'ficha_tecnica' => ['especie' => 'PISTOLA', 'capacidade_carregador' => 15]];
        $this->como($this->c['gestorAlfa'])->postJson('/api/v1/items', $base + ['categoria_id' => $arm->id])->assertCreated()->assertJsonPath('ficha_tecnica.capacidade_carregador', 15);
        $this->postJson('/api/v1/items', ['codigo' => 'MUN-T1'] + $base + ['categoria_id' => $mun->id])->assertStatus(422)->assertJsonPath('error.code', 'FICHA_SO_ARMAMENTO');
        $this->postJson('/api/v1/items', ['codigo' => 'PST-T2', 'ficha_tecnica' => ['cor' => 'preta']] + $base + ['categoria_id' => $arm->id])->assertStatus(422);
    }
}
