<?php

namespace App\Http\Controllers\Api;

use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;

/**
 * ADR-006 — operações cujas políticas institucionais estão pendentes.
 * Rotas reservadas do SRS §11 respondem 409 OPERACAO_BLOQUEADA; nenhuma aprovação simulada é persistida.
 */
class OperacoesController extends ApiController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(config('sigarb.operacoes'))
            ->map(fn ($o, $k) => ['codigo' => $k, 'estado' => Operacoes::estado($k)] + $o)->values()]);
    }

    public function bloqueada(string $operacao): never
    {
        $this->auditoria()->registrar('operacao.bloqueada', 'NEGADO', dados: ['operacao' => $operacao, 'rota' => request()->path()]);
        throw SigarbException::operacaoBloqueada($operacao);
    }
}
