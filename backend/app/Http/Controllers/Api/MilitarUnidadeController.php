<?php

namespace App\Http\Controllers\Api;

use App\Models\Militar;
use App\Models\MilitarUnidade;
use App\Models\Unidade;
use App\Support\SigarbException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * CP-12 — vínculos secundários (RF-COL-04 do documento da gestão). Histórico preservado: encerrar, nunca apagar.
 * IMPORTANTE: não concedem permissão nem visibilidade — escopo vem só de atribuicoes_papel.
 */
class MilitarUnidadeController extends ApiController
{
    public function index(Militar $militar): JsonResponse
    {
        Gate::authorize('view', $militar);

        return response()->json(['data' => MilitarUnidade::with('unidade:id,sigla,nome')->where('militar_id', $militar->id)
            ->orderByRaw('fim IS NULL DESC')->orderByDesc('inicio')->orderByDesc('id')->get()->map(fn ($v) => $this->dto($v))]);
    }

    public function store(Request $r, Militar $militar): JsonResponse
    {
        Gate::authorize('update', [$militar, null]);
        $d = $r->validate(['unidade_id' => ['required', 'integer'], 'inicio' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $unidade = Unidade::find($d['unidade_id']);
        // Unidade fora do escopo de consulta do usuário é tratada como inexistente (sem enumeração).
        if (! $unidade || ! $unidade->ativo || ! $this->authz()->pode($r->user(), 'militares.ver', $unidade->id)) {
            throw new SigarbException('UNIDADE_INVALIDA', 'Unidade inexistente, inativa ou fora do seu escopo.', 422);
        }
        if ($unidade->id === $militar->unidade_id) {
            throw new SigarbException('UNIDADE_PRINCIPAL', 'A unidade principal não pode ser vínculo secundário.', 422);
        }
        try {
            $v = DB::transaction(function () use ($r, $militar, $unidade, $d) {
                $v = MilitarUnidade::create(['militar_id' => $militar->id, 'unidade_id' => $unidade->id, 'inicio' => $d['inicio'] ?? now()->toDateString(), 'registrado_por_id' => $r->user()->id]);
                $this->auditoria()->registrar('militar.vinculo_secundario_criado', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id,
                    ['vinculo_id' => $v->id, 'unidade_id' => $unidade->id, 'inicio' => $v->inicio->toDateString()]);

                return $v;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SigarbException('VINCULO_DUPLICADO', 'Já existe vínculo ativo com esta unidade.', 409);
        }

        return response()->json($this->dto($v->load('unidade:id,sigla,nome')), 201);
    }

    public function encerrar(Request $r, Militar $militar, int $vinculo): JsonResponse
    {
        Gate::authorize('update', [$militar, null]);
        $d = $r->validate(['fim' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $v = DB::transaction(function () use ($r, $militar, $vinculo, $d) {
            $v = MilitarUnidade::where('militar_id', $militar->id)->whereKey($vinculo)->lockForUpdate()->firstOrFail();
            if ($v->fim !== null) {
                throw new SigarbException('VINCULO_ENCERRADO', 'O vínculo já está encerrado.', 409);
            }
            $fim = $d['fim'] ?? now()->toDateString();
            if ($fim < $v->inicio->toDateString()) {
                throw new SigarbException('PERIODO_INVALIDO', 'O fim não pode ser anterior ao início do vínculo.', 422);
            }
            $v->update(['fim' => $fim, 'encerrado_por_id' => $r->user()->id]);
            $this->auditoria()->registrar('militar.vinculo_secundario_encerrado', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id,
                ['vinculo_id' => $v->id, 'unidade_id' => $v->unidade_id, 'fim' => $fim]);

            return $v;
        });

        return response()->json($this->dto($v->load('unidade:id,sigla,nome')));
    }

    private function dto(MilitarUnidade $v): array
    {
        return ['id' => $v->id, 'unidade' => $v->unidade->only(['id', 'sigla', 'nome']), 'inicio' => $v->inicio->toDateString(),
            'fim' => $v->fim?->toDateString(), 'ativo' => $v->fim === null];
    }
}
