<?php

namespace Tests;

use App\Models\AtribuicaoPapel;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Papel;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Services\AutorizacaoService;
use Database\Seeders\ReferenciaSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Cenário sintético: ALFA ⊃ ALFA-1; BRAVO separado. */
    protected array $c = [];

    protected function cenario(): array
    {
        $this->seed(ReferenciaSeeder::class);
        $alfa = Unidade::factory()->create(['sigla' => 'T-ALFA']);
        $alfa1 = Unidade::factory()->sub($alfa)->create(['sigla' => 'T-ALFA-1']);
        $bravo = Unidade::factory()->create(['organizacao_id' => $alfa->organizacao_id, 'sigla' => 'T-BRAVO']);
        $this->c = [
            'alfa' => $alfa->fresh(), 'alfa1' => $alfa1->fresh(), 'bravo' => $bravo->fresh(),
            'localAlfa' => LocalEstoque::factory()->create(['unidade_id' => $alfa->id]),
            'localAlfa1' => LocalEstoque::factory()->create(['unidade_id' => $alfa1->id]),
            'localBravo' => LocalEstoque::factory()->create(['unidade_id' => $bravo->id]),
            'admin' => $this->usuarioCom('ADMIN', null),
            'gestorAlfa' => $this->usuarioCom('GESTOR', $alfa->id),
            'operadorAlfa1' => $this->usuarioCom('OPERADOR', $alfa1->id),
            'gestorBravo' => $this->usuarioCom('GESTOR', $bravo->id),
            'auditor' => $this->usuarioCom('AUDITOR', null),
            'auditorAlfa' => $this->usuarioCom('AUDITOR', $alfa->id),
            'aprovador' => $this->usuarioCom('APROVADOR', $alfa->id),
            'matInd' => Material::factory()->create(),
            'matLote' => Material::factory()->lote()->create(),
        ];

        return $this->c;
    }

    protected function usuarioCom(string $papel, ?int $unidadeId): Usuario
    {
        $u = Usuario::factory()->create();
        AtribuicaoPapel::create(['usuario_id' => $u->id, 'papel_id' => Papel::where('codigo', $papel)->value('id'), 'unidade_id' => $unidadeId]);

        return $u;
    }

    protected function como(Usuario $u): static
    {
        app(AutorizacaoService::class)->limpar();

        return $this->actingAs($u, 'web');
    }

    /**
     * CP-14: todas as operações foram decididas pelo responsável. Para continuar testando o portão (ADR-006),
     * os testes devolvem operações ao estado BLOQUEADA como se ainda dependessem de pendência institucional.
     */
    protected function bloquear(string ...$ops): void
    {
        foreach ($ops as $op) {
            config(["sigarb.operacoes.$op.estado" => 'BLOQUEADA', 'sigarb.operacoes_provisorias' => []]);
        }
    }

    protected function chave(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    protected function entradaLote(LocalEstoque $local, int $qtd, string $lote = 'L-001', ?string $validade = null): array
    {
        return ['tipo' => 'ENTRADA', 'local_destino_id' => $local->id, 'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-T-1',
            'itens' => [array_filter(['material_id' => $this->c['matLote']->id, 'lote_codigo' => $lote, 'quantidade' => $qtd, 'validade' => $validade])]];
    }

    /** O identificador é gerado pelo sistema; $id vira o número de série (quando $serie não é dado) para o teste achar o item. */
    protected function entradaIndividual(LocalEstoque $local, string $id, ?string $serie = null): array
    {
        return ['tipo' => 'ENTRADA', 'local_destino_id' => $local->id, 'origem_descricao' => 'Origem fictícia', 'documento_referencia' => 'DOC-T-2',
            'itens' => [['material_id' => $this->c['matInd']->id, 'numero_serie' => $serie ?? $id]]];
    }
}
