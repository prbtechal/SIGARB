<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditoriaService;
use App\Services\AutorizacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

abstract class ApiController extends Controller
{
    protected function authz(): AutorizacaoService
    {
        return app(AutorizacaoService::class);
    }

    protected function auditoria(): AuditoriaService
    {
        return app(AuditoriaService::class);
    }

    protected function exigir(string $perm, $unidade = null): void
    {
        Gate::allowIf(fn ($u) => $this->authz()->pode($u, $perm, $unidade));
    }

    /** Paginação validada no servidor (SRS §11.1). Ordenação apenas por colunas da lista branca. */
    protected function paginar(Request $r, $query, callable $map, array $ordenaveis = ['id'], string $padrao = '-id'): JsonResponse
    {
        $v = Validator::make($r->query(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', 'in:'.implode(',', array_merge($ordenaveis, array_map(fn ($c) => "-$c", $ordenaveis)))],
        ])->validate();
        $sort = $v['sort'] ?? $padrao;
        $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
        if (ltrim($sort, '-') !== 'id') {
            $query->orderBy('id', 'desc'); // desempate estável
        }
        $p = $query->paginate((int) ($v['per_page'] ?? 20));

        return response()->json([
            'data' => collect($p->items())->map($map)->values(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ]);
    }

    /** Escapa curingas do LIKE para busca textual segura. */
    protected function like(string $termo): string
    {
        return '%'.addcslashes($termo, '%_\\').'%';
    }
}
