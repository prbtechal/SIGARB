<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MaterialRequest;
use App\Models\Calibre;
use App\Models\Categoria;
use App\Models\Fabricante;
use App\Models\Material;
use App\Models\PostoGraduacao;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** RF-007/008/009, RF-029 (listas de referência), UC-03. */
class CatalogoController extends ApiController
{
    private const REFERENCIAS = [
        'categorias' => [Categoria::class, ['codigo' => ['required', 'regex:/^[A-Z0-9_-]{2,30}$/', 'unique:categorias,codigo'], 'nome' => ['required', 'string', 'max:80'], 'natureza' => ['required', 'in:ARMAMENTO,MUNICAO,EQUIPAMENTO,PRODUTO,TREINAMENTO']]],
        'fabricantes' => [Fabricante::class, ['nome' => ['required', 'string', 'min:2', 'max:120', 'unique:fabricantes,nome']]],
        'calibres' => [Calibre::class, ['nome' => ['required', 'string', 'min:1', 'max:40', 'unique:calibres,nome']]],
    ];

    public function referencias(Request $r, string $tipo): JsonResponse
    {
        if ($tipo === 'postos-graduacoes') {
            $this->exigir('militares.ver');

            return response()->json(['data' => PostoGraduacao::orderBy('ordem')->get()]);
        }
        abort_unless(isset(self::REFERENCIAS[$tipo]), 404);
        $this->exigir('catalogo.ver');

        return response()->json(['data' => self::REFERENCIAS[$tipo][0]::orderBy('nome')->get()]);
    }

    public function criarReferencia(Request $r, string $tipo): JsonResponse
    {
        abort_unless(isset(self::REFERENCIAS[$tipo]), 404);
        $this->exigir('catalogo.gerir');
        [$cls, $regras] = self::REFERENCIAS[$tipo];
        $d = $r->validate($regras);
        $m = $cls::create($d);
        $this->auditoria()->registrar("catalogo.$tipo.criado", 'SUCESSO', $tipo, $m->id, dados: $d);

        return response()->json($m, 201);
    }

    public function materiais(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', Material::class);
        $r->validate(['q' => ['nullable', 'string', 'max:80'], 'modo_controle' => ['nullable', 'in:INDIVIDUAL,LOTE'], 'categoria_id' => ['nullable', 'integer'], 'ativo' => ['nullable', 'boolean']]);
        $q = Material::query()->with(['categoria', 'fabricante', 'calibre']);
        if ($t = $r->query('q')) {
            $q->where(fn ($w) => $w->where('nome', 'like', $this->like($t))->orWhere('codigo', 'like', $this->like($t))->orWhere('modelo', 'like', $this->like($t)));
        }
        foreach (['modo_controle', 'categoria_id'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->query($f));
            }
        }
        if ($r->has('ativo')) {
            $q->where('ativo', $r->boolean('ativo'));
        }

        return $this->paginar($r, $q, fn ($m) => $this->dto($m), ['id', 'nome', 'codigo'], 'nome');
    }

    public function material(Material $material): JsonResponse
    {
        Gate::authorize('view', $material);

        return response()->json($this->dto($material->load(['categoria', 'fabricante', 'calibre'])) + ['possui_estoque' => $material->possuiEstoque()]);
    }

    public function criarMaterial(MaterialRequest $r): JsonResponse
    {
        Gate::authorize('create', Material::class);
        $m = Material::create($r->validated());
        $this->auditoria()->registrar('material.criado', 'SUCESSO', 'material', $m->id, dados: $r->validated());

        return response()->json($this->dto($m->load(['categoria', 'fabricante', 'calibre'])), 201);
    }

    public function atualizarMaterial(MaterialRequest $r, Material $material): JsonResponse
    {
        Gate::authorize('update', $material);
        $d = $r->validated();
        if (isset($d['modo_controle']) && $d['modo_controle'] !== $material->modo_controle && $material->possuiEstoque()) {
            throw new SigarbException('MODO_CONTROLE_IMUTAVEL', 'O modo de controle não pode mudar depois que o material possui estoque registrado.', 409);
        }
        $antes = $material->only(array_keys($d));
        $material->update($d);
        $this->auditoria()->registrar('material.atualizado', 'SUCESSO', 'material', $material->id, dados: ['antes' => $antes, 'depois' => $d]);

        return response()->json($this->dto($material->load(['categoria', 'fabricante', 'calibre'])));
    }

    private function dto(Material $m): array
    {
        return [
            'id' => $m->id, 'codigo' => $m->codigo, 'nome' => $m->nome, 'modelo' => $m->modelo,
            'modo_controle' => $m->modo_controle, 'unidade_medida' => $m->unidade_medida, 'ativo' => $m->ativo,
            'categoria' => ['id' => $m->categoria->id, 'nome' => $m->categoria->nome, 'natureza' => $m->categoria->natureza],
            'fabricante' => $m->fabricante ? ['id' => $m->fabricante->id, 'nome' => $m->fabricante->nome] : null,
            'calibre' => $m->calibre ? ['id' => $m->calibre->id, 'nome' => $m->calibre->nome] : null,
        ];
    }
}
