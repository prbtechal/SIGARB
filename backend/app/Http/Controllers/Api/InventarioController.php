<?php

namespace App\Http\Controllers\Api;

use App\Models\Inventario;
use App\Models\LocalEstoque;
use App\Services\InventarioService;
use App\Support\Operacoes;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** RF-020 — inventário (regra provisória). Código ULID; 404 uniforme fora do escopo. */
class InventarioController extends ApiController
{
    public function __construct(private InventarioService $svc) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('estoque.ver');
        $f = $r->validate(['estado' => ['nullable', 'in:EM_CONTAGEM,AGUARDANDO_APROVACAO,AJUSTADO,REJEITADO,CANCELADO']]);
        $q = Inventario::query()->with(['local:id,codigo,nome'])->withCount(['itens', 'itens as divergencias_count' => fn ($w) => $w->whereNotNull('contado')->whereColumn('contado', '!=', 'esperado')]);
        $this->authz()->escopar($q, $r->user(), 'estoque.ver');
        if (! empty($f['estado'])) {
            $q->where('estado', $f['estado']);
        }

        return $this->paginar($r, $q, fn ($i) => $this->resumo($i), ['id'], '-id');
    }

    public function show(Request $r, string $codigo): JsonResponse
    {
        $i = $this->obter($r, $codigo);
        $i->load(['abertoPor:id,nome', 'contadoPor:id,nome', 'decididoPor:id,nome', 'itens.lote.material:id,codigo,nome', 'itens.itemIndividual.material:id,codigo,nome']);
        $u = $r->user();
        $contar = $this->authz()->pode($u, 'inventario.contar', $i->unidade_id);

        return response()->json($this->resumo($i) + [
            'aberto_por' => $i->abertoPor->nome, 'contado_por' => $i->contadoPor?->nome, 'decidido_por' => $i->decididoPor?->nome,
            'decidido_em' => $i->decidido_em?->toIso8601String(), 'observacao' => $i->observacao,
            'ajuste_falta_id' => $i->ajuste_falta_id, 'ajuste_sobra_id' => $i->ajuste_sobra_id,
            'itens' => $i->itens->map(fn ($l) => [
                'id' => $l->id, 'tipo' => $l->lote_id ? 'LOTE' : 'INDIVIDUAL', 'esperado' => (int) $l->esperado, 'contado' => $l->contado === null ? null : (int) $l->contado,
                'material' => ($l->lote ?? $l->itemIndividual)->material->only(['id', 'codigo', 'nome']),
                'identificacao' => $l->lote ? 'Lote '.$l->lote->codigo : $l->itemIndividual->identificador,
            ]),
            'acoes' => [
                'contar' => $i->estado === 'EM_CONTAGEM' && $contar,
                'cancelar' => $i->estado === 'EM_CONTAGEM' && $contar,
                'decidir' => $i->estado === 'AGUARDANDO_APROVACAO' && Operacoes::estado('INVENTARIO') !== 'BLOQUEADA'
                    && $this->authz()->pode($u, 'inventario.aprovar', $i->unidade_id)
                    && ((! in_array($u->id, [$i->aberto_por, $i->contado_por], true) && ! InventarioService::contou($i, $u))
                        || $this->authz()->temPapel($u, config('sigarb.politicas.autoaprovacao_papeis'), $i->unidade_id)),
            ],
        ]);
    }

    public function store(Request $r): JsonResponse
    {
        Operacoes::exigir('INVENTARIO');
        $d = $r->validate(['local_estoque_id' => ['required', 'integer', 'exists:locais_estoque,id']]);
        $local = LocalEstoque::findOrFail($d['local_estoque_id']);
        $this->exigir('inventario.contar', $local->unidade_id);

        return $this->show($r, $this->svc->abrir($r->user(), $local)->codigo)->setStatusCode(201);
    }

    public function contar(Request $r, string $codigo): JsonResponse
    {
        $i = $this->obter($r, $codigo);
        $d = $r->validate(['versao' => ['required', 'integer'], 'contagens' => ['required', 'array', 'min:1', 'max:2000'],
            'contagens.*.id' => ['required', 'integer', 'distinct'], 'contagens.*.contado' => ['required', 'integer', 'min:0', 'max:10000000']]);
        $this->exigir('inventario.contar', $i->unidade_id);
        $this->svc->contar($r->user(), $i, collect($d['contagens'])->pluck('contado', 'id')->all(), $d['versao']);

        return $this->show($r, $codigo);
    }

    public function enviar(Request $r, string $codigo): JsonResponse
    {
        $i = $this->obter($r, $codigo);
        $d = $r->validate(['versao' => ['required', 'integer']]);
        $this->exigir('inventario.contar', $i->unidade_id);
        $this->svc->concluirContagem($r->user(), $i, $d['versao']);

        return $this->show($r, $codigo);
    }

    public function cancelar(Request $r, string $codigo): JsonResponse
    {
        $i = $this->obter($r, $codigo);
        $d = $r->validate(['versao' => ['required', 'integer'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $this->exigir('inventario.contar', $i->unidade_id);
        $this->svc->cancelar($r->user(), $i, $d['motivo'], $d['versao']);

        return $this->show($r, $codigo);
    }

    public function decidir(Request $r, string $codigo): JsonResponse
    {
        $i = $this->obter($r, $codigo);
        $d = $r->validate(['versao' => ['required', 'integer'], 'decisao' => ['required', 'in:APROVAR,REJEITAR'], 'observacao' => ['nullable', 'string', 'max:500']]);
        $this->exigir('inventario.aprovar', $i->unidade_id);
        $this->svc->decidir($r->user(), $i, $d['decisao'] === 'APROVAR', $d['observacao'] ?? null, $d['versao']);

        return $this->show($r, $codigo);
    }

    private function obter(Request $r, string $codigo): Inventario
    {
        $i = Inventario::with('local:id,codigo,nome')->where('codigo', $codigo)->first();
        if (! $i || ! $this->authz()->pode($r->user(), 'estoque.ver', $i->unidade_id)) {
            throw new SigarbException('NAO_ENCONTRADO', 'Inventário não encontrado.', 404);
        }

        return $i;
    }

    private function resumo(Inventario $i): array
    {
        return ['codigo' => $i->codigo, 'estado' => $i->estado, 'versao' => $i->versao, 'local' => $i->local->only(['id', 'codigo', 'nome']),
            'criado_em' => $i->created_at->toIso8601String(), 'linhas' => $i->itens_count ?? $i->itens()->count(),
            'divergencias' => $i->divergencias_count ?? $i->itens()->whereNotNull('contado')->whereColumn('contado', '!=', 'esperado')->count()];
    }
}
