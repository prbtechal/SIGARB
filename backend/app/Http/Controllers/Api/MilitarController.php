<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MilitarRequest;
use App\Models\Militar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** RF-005, RF-026, §2.1. Matrícula mascarada sem `militares.ver_detalhe` (ADR-009). */
class MilitarController extends ApiController
{
    public function index(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', Militar::class);
        $r->validate(['q' => ['nullable', 'string', 'max:80'], 'situacao' => ['nullable', 'in:ATIVO,AFASTADO,INATIVO'], 'unidade_id' => ['nullable', 'integer']]);
        $q = Militar::query()->with(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem']);
        $this->authz()->escopar($q->getQuery(), $r->user(), 'militares.ver');
        if ($t = $r->query('q')) {
            // Busca por matrícula só nas unidades em que a matrícula completa é visível (evita oráculo da máscara).
            $detalhe = $this->authz()->unidadesPermitidas($r->user(), 'militares.ver_detalhe');
            $q->where(function ($w) use ($t, $detalhe) {
                $w->where('nome', 'like', $this->like($t))->orWhere('nome_guerra', 'like', $this->like($t));
                if ($detalhe === null) {
                    $w->orWhere('matricula', $t);
                } elseif ($detalhe !== []) {
                    $w->orWhere(fn ($m) => $m->where('matricula', $t)->whereIn('unidade_id', $detalhe));
                }
            });
        }
        foreach (['situacao', 'unidade_id'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->query($f));
            }
        }

        return $this->paginar($r, $q, fn ($m) => $this->dto($r, $m), ['id', 'nome', 'nome_guerra'], 'nome');
    }

    public function show(Request $r, Militar $militar): JsonResponse
    {
        Gate::authorize('view', $militar);
        $militar->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem', 'usuario:id,login,militar_id']);
        $detalhe = $this->authz()->pode($r->user(), 'militares.ver_detalhe', $militar->unidade_id);
        if ($detalhe) {
            // Consulta sensível (RN-020; política provisória P-08).
            $this->auditoria()->registrar('militar.consulta_detalhe', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id);
        }
        $dto = $this->dto($r, $militar);
        if ($this->authz()->global($r->user(), 'usuarios.ver') || $detalhe) {
            $dto['usuario'] = $militar->usuario ? ['id' => $militar->usuario->id, 'login' => $militar->usuario->login] : null;
        }

        return response()->json($dto);
    }

    public function store(MilitarRequest $r): JsonResponse
    {
        $d = $r->validated();
        Gate::authorize('create', [Militar::class, (int) $d['unidade_id']]);
        $m = Militar::create($d);
        $this->auditoria()->registrar('militar.criado', 'SUCESSO', 'militar', $m->id, $m->unidade_id, ['matricula' => $m->matricula]);

        return response()->json($this->dto($r, $m->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem'])), 201);
    }

    public function update(MilitarRequest $r, Militar $militar): JsonResponse
    {
        $d = $r->validated();
        Gate::authorize('update', [$militar, isset($d['unidade_id']) ? (int) $d['unidade_id'] : null]);
        $antes = $militar->only(array_keys($d));
        $militar->update($d);
        $this->auditoria()->registrar('militar.atualizado', 'SUCESSO', 'militar', $militar->id, $militar->unidade_id, ['antes' => $antes, 'depois' => $d]);

        return response()->json($this->dto($r, $militar->load(['unidade:id,sigla', 'postoGraduacao:id,sigla,ordem'])));
    }

    private function dto(Request $r, Militar $m): array
    {
        $detalhe = $this->authz()->pode($r->user(), 'militares.ver_detalhe', $m->unidade_id);

        return [
            'id' => $m->id, 'nome' => $m->nome, 'nome_guerra' => $m->nome_guerra,
            'matricula' => $detalhe ? $m->matricula : '•••'.substr($m->matricula, -3),
            'matricula_completa' => $detalhe,
            'situacao' => $m->situacao,
            'posto_graduacao' => ['id' => $m->postoGraduacao->id, 'sigla' => $m->postoGraduacao->sigla],
            'unidade' => ['id' => $m->unidade->id, 'sigla' => $m->unidade->sigla],
            'pode_editar' => $this->authz()->pode($r->user(), 'militares.gerir', $m->unidade_id),
        ];
    }
}
