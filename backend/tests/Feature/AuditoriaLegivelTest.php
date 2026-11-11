<?php

namespace Tests\Feature;

use App\Models\Movimentacao;
use App\Services\AuditoriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Apresentação legível da auditoria: rótulo da ação, frase e recurso resolvido no escopo de quem consulta. */
class AuditoriaLegivelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario();
    }

    public function test_frase_rotulo_e_recurso_legivel_com_link(): void
    {
        $this->como($this->c['gestorAlfa'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localAlfa'], 5))->assertCreated();
        $mov = Movimentacao::latest('id')->first();

        $e = $this->como($this->c['auditor'])->getJson('/api/v1/audit-events?acao=estoque.entrada')->assertOk()->json('data.0');
        $this->assertSame('estoque.entrada', $e['acao']); // contrato original preservado
        $this->assertSame('Entrada de estoque', $e['acao_rotulo']);
        $this->assertSame("Movimentação #$mov->id – Entrada de estoque em {$this->c['localAlfa']->codigo}", $e['recurso_rotulo']);
        $this->assertSame("/movimentacoes/$mov->id", $e['recurso_link']);
        $this->assertSame("Unidade T-ALFA – {$this->c['alfa']->nome}", $e['unidade_rotulo']);
        $this->assertSame("{$this->c['gestorAlfa']->nome} registrou a entrada: {$e['recurso_rotulo']}", $e['frase']);
    }

    public function test_usuario_aparece_com_cpf_mascarado(): void
    {
        $this->c['operadorAlfa1']->forceFill(['login' => '52998224725', 'nome' => 'José Filho'])->save();
        app(AuditoriaService::class)->registrar('usuario.atualizado', 'SUCESSO', 'usuario', $this->c['operadorAlfa1']->id, usuarioId: $this->c['admin']->id);

        $e = $this->como($this->c['auditor'])->getJson('/api/v1/audit-events?acao=usuario.atualizado')->assertOk()->json('data.0');
        $this->assertSame('Usuário José Filho (CPF ***.***.***-25)', $e['recurso_rotulo']);
        $this->assertStringNotContainsString('52998224725', $e['frase']);
        $this->assertSame('Usuário alterado', $e['acao_rotulo']);
    }

    public function test_sem_acesso_ao_recurso_ve_apenas_o_generico(): void
    {
        $this->como($this->c['gestorBravo'])->withHeaders($this->chave())->postJson('/api/v1/movements', $this->entradaLote($this->c['localBravo'], 3, 'LB'))->assertCreated();
        $movBravo = Movimentacao::latest('id')->first();
        $s = app(AuditoriaService::class);
        // Eventos na unidade ALFA (visíveis ao auditor de ALFA) apontando para recursos fora do seu alcance.
        $s->registrar('teste.ref_bravo', 'SUCESSO', 'movimentacao', $movBravo->id, $this->c['alfa']->id);
        $s->registrar('teste.ref_usuario', 'SUCESSO', 'usuario', $this->c['gestorBravo']->id, $this->c['alfa']->id);

        $dados = collect($this->como($this->c['auditorAlfa'])->getJson('/api/v1/audit-events?acao=teste.')->assertOk()->json('data'))->keyBy('acao');
        $this->assertSame("Movimentação #$movBravo->id", $dados['teste.ref_bravo']['recurso_rotulo']);
        $this->assertNull($dados['teste.ref_bravo']['recurso_link']);
        $this->assertSame("Usuário #{$this->c['gestorBravo']->id}", $dados['teste.ref_usuario']['recurso_rotulo']);
        $this->assertSame('Teste – ref bravo', $dados['teste.ref_bravo']['acao_rotulo']); // fallback automático

        // O auditor global vê o mesmo evento resolvido.
        $global = collect($this->como($this->c['auditor'])->getJson('/api/v1/audit-events?acao=teste.')->json('data'))->keyBy('acao');
        $this->assertStringStartsWith("Movimentação #$movBravo->id – ", $global['teste.ref_bravo']['recurso_rotulo']);
        $this->assertStringStartsWith('Usuário ', $global['teste.ref_usuario']['recurso_rotulo']);
        $this->assertStringNotContainsString('#', $global['teste.ref_usuario']['recurso_rotulo']);
    }

    public function test_lista_de_acoes_para_filtro_exige_permissao(): void
    {
        $acoes = collect($this->como($this->c['auditor'])->getJson('/api/v1/audit-events/actions')->assertOk()->json('data'));
        $this->assertSame('Consulta à auditoria', $acoes->firstWhere('codigo', 'auditoria.consulta')['rotulo']);
        $this->como($this->c['gestorAlfa'])->getJson('/api/v1/audit-events/actions')->assertForbidden();
    }
}
