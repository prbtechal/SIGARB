<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LocalEstoqueRequest;
use App\Http\Requests\UnidadeRequest;
use App\Models\LocalEstoque;
use App\Models\Organizacao;
use App\Models\Unidade;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** RF-006 — organização, unidades e locais de estoque, sempre filtrados por escopo. */
class OrganizacaoController extends ApiController
{
    public function organizacoes(Request $r): JsonResponse
    {
        $this->exigir('organizacao.ver');
        $ids = $this->authz()->unidadesPermitidas($r->user(), 'organizacao.ver');
        $q = Organizacao::query();
        if ($ids !== null) {
            $q->whereIn('id', Unidade::whereIn('id', $ids ?: [0])->select('organizacao_id'));
        }

        return response()->json(['data' => $q->orderBy('sigla')->get(['id', 'sigla', 'nome', 'ativo'])]);
    }

    public function criarOrganizacao(Request $r): JsonResponse
    {
        Gate::allowIf(fn ($u) => $this->authz()->global($u, 'organizacao.gerir'));
        $d = $r->validate(['sigla' => ['required', 'regex:/^[A-Z0-9-]{2,20}$/', 'unique:organizacoes,sigla'], 'nome' => ['required', 'string', 'min:3', 'max:160']]);
        $o = Organizacao::create($d);
        $this->auditoria()->registrar('organizacao.criada', 'SUCESSO', 'organizacao', $o->id, dados: $d);

        return response()->json($o, 201);
    }

    public function unidades(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', Unidade::class);
        $q = Unidade::query()->with('organizacao:id,sigla');
        $this->authz()->escopar($q->getQuery(), $r->user(), 'organizacao.ver', 'id');
        if ($r->filled('organizacao_id')) {
            $q->where('organizacao_id', (int) $r->query('organizacao_id'));
        }

        return response()->json(['data' => $q->orderBy('caminho')->get()->map(fn (Unidade $u) => $this->unidade($u))]);
    }

    public function criarUnidade(UnidadeRequest $r): JsonResponse
    {
        $d = $r->validated();
        Gate::authorize('create', [Unidade::class, $d['unidade_superior_id'] ?? null]);
        if (! empty($d['unidade_superior_id'])) {
            $sup = Unidade::findOrFail($d['unidade_superior_id']);
            if ($sup->organizacao_id !== (int) $d['organizacao_id']) {
                throw new SigarbException('HIERARQUIA_INVALIDA', 'A unidade superior pertence a outra organização.', 422);
            }
        }
        if (Unidade::where('organizacao_id', $d['organizacao_id'])->where('sigla', $d['sigla'])->exists()) {
            throw new SigarbException('SIGLA_DUPLICADA', 'Já existe unidade com esta sigla na organização.', 422);
        }
        $u = DB::transaction(function () use ($d) {
            $u = Unidade::create($d);
            $this->auditoria()->registrar('unidade.criada', 'SUCESSO', 'unidade', $u->id, $u->id, $d);

            return $u;
        });

        return response()->json($this->unidade($u->fresh('organizacao')), 201);
    }

    public function atualizarUnidade(UnidadeRequest $r, Unidade $unidade): JsonResponse
    {
        Gate::authorize('update', $unidade);
        $antes = $unidade->only(array_keys($r->validated()));
        $unidade->update($r->validated());
        $this->auditoria()->registrar('unidade.atualizada', 'SUCESSO', 'unidade', $unidade->id, $unidade->id, ['antes' => $antes, 'depois' => $r->validated()]);

        return response()->json($this->unidade($unidade->fresh('organizacao')));
    }

    public function locais(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', LocalEstoque::class);
        $q = LocalEstoque::query()->with('unidade:id,sigla,nome');
        $this->authz()->escopar($q->getQuery(), $r->user(), 'organizacao.ver');
        if ($r->filled('unidade_id')) {
            $q->where('unidade_id', (int) $r->query('unidade_id'));
        }
        if ($r->boolean('somente_ativos')) {
            $q->where('ativo', true);
        }

        return response()->json(['data' => $q->orderBy('codigo')->get()->map(fn ($l) => $this->local($l))]);
    }

    public function criarLocal(LocalEstoqueRequest $r): JsonResponse
    {
        $d = $r->validated();
        Gate::authorize('create', [LocalEstoque::class, (int) $d['unidade_id']]);
        $l = LocalEstoque::create($d);
        $this->auditoria()->registrar('local.criado', 'SUCESSO', 'local_estoque', $l->id, $l->unidade_id, $d);

        return response()->json($this->local($l->load('unidade')), 201);
    }

    public function atualizarLocal(LocalEstoqueRequest $r, LocalEstoque $local): JsonResponse
    {
        Gate::authorize('update', $local);
        $antes = $local->only(array_keys($r->validated()));
        $local->update($r->validated());
        $this->auditoria()->registrar('local.atualizado', 'SUCESSO', 'local_estoque', $local->id, $local->unidade_id, ['antes' => $antes, 'depois' => $r->validated()]);

        return response()->json($this->local($local->load('unidade')));
    }

    private function unidade(Unidade $u): array
    {
        return [
            'id' => $u->id, 'sigla' => $u->sigla, 'nome' => $u->nome, 'tipo' => $u->tipo, 'ativo' => $u->ativo,
            'organizacao' => ['id' => $u->organizacao->id, 'sigla' => $u->organizacao->sigla],
            'unidade_superior_id' => $u->unidade_superior_id,
            'nivel' => substr_count($u->caminho, '/') - 2,
        ];
    }

    private function local(LocalEstoque $l): array
    {
        return ['id' => $l->id, 'codigo' => $l->codigo, 'nome' => $l->nome, 'tipo' => $l->tipo, 'ativo' => $l->ativo,
            'unidade' => ['id' => $l->unidade->id, 'sigla' => $l->unidade->sigla, 'nome' => $l->unidade->nome]];
    }
}
