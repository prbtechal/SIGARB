<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MaterialRequest;
use App\Models\Calibre;
use App\Models\Categoria;
use App\Models\Fabricante;
use App\Models\Material;
use App\Models\NivelProtecao;
use App\Models\PostoGraduacao;
use App\Models\TipoDocumento;
use App\Models\TipoMaterial;
use App\Support\PerfilMaterial;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** RF-007/008/009, RF-029 (listas de referência), UC-03. */
class CatalogoController extends ApiController
{
    private const REFERENCIAS = [
        'categorias' => [Categoria::class, ['codigo' => ['required', 'regex:/^[A-Z0-9_-]{2,30}$/', 'unique:categorias,codigo'], 'nome' => ['required', 'string', 'max:80'], 'natureza' => ['required', 'in:ARMAMENTO,MUNICAO,EQUIPAMENTO,PRODUTO,TREINAMENTO']]],
        'niveis-protecao' => [NivelProtecao::class, []], // CP-13: escrita só por /reference-lists
        'fabricantes' => [Fabricante::class, ['nome' => ['required', 'string', 'min:2', 'max:120', 'unique:fabricantes,nome']]],
        'tipos-documento' => [TipoDocumento::class, ['codigo' => ['required', 'regex:/^[A-Z0-9_-]{2,30}$/', 'unique:tipos_documento,codigo'], 'nome' => ['required', 'string', 'max:80']]],
        'calibres' => [Calibre::class, ['nome' => ['required', 'string', 'min:1', 'max:40', 'unique:calibres,nome']]],
    ];

    public function referencias(Request $r, string $tipo): JsonResponse
    {
        if ($tipo === 'postos-graduacoes') {
            $this->exigir('militares.ver');

            return response()->json(['data' => PostoGraduacao::orderBy('ordem')->get()]);
        }
        if ($tipo === 'tipos-material') {
            $this->exigir('catalogo.ver');
            $r->validate(['categoria_id' => ['nullable', 'integer']]);

            return response()->json(['data' => TipoMaterial::where('ativo', true)->when($r->filled('categoria_id'), fn ($q) => $q->where('categoria_id', $r->query('categoria_id')))
                ->orderBy('nome')->get(['id', 'categoria_id', 'nome', 'descricao', 'perfil']),
                // Regras de cada perfil para o formulário dinâmico (o servidor revalida sempre).
                'perfis' => PerfilMaterial::REGRAS, 'niveis_protecao' => NivelProtecao::ativos()]);
        }
        abort_unless(isset(self::REFERENCIAS[$tipo]), 404);
        $this->exigir('catalogo.ver');

        // Para formulários: só valores ativos (desativados continuam visíveis em registros antigos).
        return response()->json(['data' => self::REFERENCIAS[$tipo][0]::where('ativo', true)->orderBy('nome')->get()]);
    }

    public function materiais(Request $r): JsonResponse
    {
        Gate::authorize('viewAny', Material::class);
        $r->validate(['q' => ['nullable', 'string', 'max:80'], 'modo_controle' => ['nullable', 'in:INDIVIDUAL,LOTE'], 'categoria_id' => ['nullable', 'integer'], 'tipo_id' => ['nullable', 'integer'], 'ativo' => ['nullable', 'boolean']]);
        $q = Material::query()->with(['categoria', 'fabricante', 'calibre', 'tipo']);
        if ($t = $r->query('q')) {
            $q->where(fn ($w) => $w->where('nome', 'like', $this->like($t))->orWhere('codigo', 'like', $this->like($t))->orWhere('modelo', 'like', $this->like($t)));
        }
        foreach (['modo_controle', 'categoria_id', 'tipo_id'] as $f) {
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

        return response()->json($this->dto($material->load(['categoria', 'fabricante', 'calibre', 'tipo'])) + ['possui_estoque' => $material->possuiEstoque()]);
    }

    public function criarMaterial(MaterialRequest $r): JsonResponse
    {
        Gate::authorize('create', Material::class);
        $this->exigirArmamentoParaFicha($r->validated(), null);
        $d = $r->validated();
        // Compatível com clientes antigos: sem tipo, usa o tipo "Geral" da categoria.
        $d['tipo_id'] ??= TipoMaterial::where('categoria_id', $d['categoria_id'])->where('nome', 'Geral')->value('id');
        $this->validarPerfil($d, null);
        $m = Material::create($d);
        $this->auditoria()->registrar('material.criado', 'SUCESSO', 'material', $m->id, dados: $d);

        return response()->json($this->dto($m->load(['categoria', 'fabricante', 'calibre', 'tipo'])), 201);
    }

    public function atualizarMaterial(MaterialRequest $r, Material $material): JsonResponse
    {
        Gate::authorize('update', $material);
        $d = $r->validated();
        if (isset($d['modo_controle']) && $d['modo_controle'] !== $material->modo_controle && $material->possuiEstoque()) {
            throw new SigarbException('MODO_CONTROLE_IMUTAVEL', 'O modo de controle não pode mudar depois que o material possui estoque registrado.', 409);
        }
        $this->exigirArmamentoParaFicha($d, $material);
        $this->validarPerfil($d, $material);
        $antes = $material->only(array_keys($d));
        $material->update($d);
        $this->auditoria()->registrar('material.atualizado', 'SUCESSO', 'material', $material->id, dados: ['antes' => $antes, 'depois' => $d]);

        return response()->json($this->dto($material->load(['categoria', 'fabricante', 'calibre', 'tipo'])));
    }

    /** CP-12 — tipo pertence à categoria; campos obrigatórios/inaplicáveis conforme o perfil do tipo (estado final). */
    private function validarPerfil(array $d, ?Material $m): void
    {
        $final = array_merge($m?->only(['categoria_id', 'tipo_id', 'modo_controle', 'fabricante_id', 'modelo', 'calibre_id', ...PerfilMaterial::ATRIBUTOS_MATERIAL]) ?? [], $d);
        if (empty($final['tipo_id'])) {
            return; // material legado ainda sem tipo: regras antigas
        }
        $tipo = TipoMaterial::find($final['tipo_id']);
        if (! $tipo || (int) $tipo->categoria_id !== (int) $final['categoria_id']) {
            throw ValidationException::withMessages(['tipo_id' => ['O tipo selecionado não pertence à categoria.']]);
        }
        PerfilMaterial::validarMaterial($tipo->perfil, $final);
    }

    private function exigirArmamentoParaFicha(array $d, ?Material $m): void
    {
        if (empty($d['ficha_tecnica'])) {
            return;
        }
        $natureza = Categoria::whereKey($d['categoria_id'] ?? $m?->categoria_id)->value('natureza');
        if ($natureza !== 'ARMAMENTO') {
            throw new SigarbException('FICHA_SO_ARMAMENTO', 'Ficha técnica se aplica somente a materiais da natureza armamento.', 422);
        }
    }

    private function dto(Material $m): array
    {
        return [
            'id' => $m->id, 'codigo' => $m->codigo, 'nome' => $m->nome, 'modelo' => $m->modelo,
            'modo_controle' => $m->modo_controle, 'unidade_medida' => $m->unidade_medida, 'ativo' => $m->ativo,
            'categoria' => ['id' => $m->categoria->id, 'nome' => $m->categoria->nome, 'natureza' => $m->categoria->natureza],
            'fabricante' => $m->fabricante ? ['id' => $m->fabricante->id, 'nome' => $m->fabricante->nome] : null,
            'calibre' => $m->calibre ? ['id' => $m->calibre->id, 'nome' => $m->calibre->nome] : null,
            'tipo' => $m->tipo ? ['id' => $m->tipo->id, 'nome' => $m->tipo->nome, 'perfil' => $m->tipo->perfil] : null,
            'marca' => $m->marca, 'compatibilidade' => $m->compatibilidade, 'cor' => $m->cor, 'tamanho' => $m->tamanho,
            'ficha_tecnica' => $m->ficha_tecnica,
        ];
    }
}
