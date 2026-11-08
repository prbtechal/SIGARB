<?php

namespace App\Http\Controllers\Api;

use App\Models\TipoMaterial;
use App\Services\ReferenciaService;
use App\Support\PerfilMaterial;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * RF-029 — administração de listas de referência (permissão global `referencias.gerir`).
 * Sem exclusão: valores são desativados com justificativa. Valor em uso não muda de significado.
 * Nenhuma configuração aqui habilita operações bloqueadas (portão em config/sigarb.php, não editável).
 */
class ReferenciaController extends ApiController
{
    public function __construct(private ReferenciaService $refs) {}

    private function exigirGestao(): void
    {
        Gate::allowIf(fn ($u) => $this->authz()->global($u, 'referencias.gerir'));
    }

    public function listas(): JsonResponse
    {
        $this->exigirGestao();

        return response()->json(['data' => collect(ReferenciaService::LISTAS)->map(fn ($d, $k) => ['tipo' => $k, 'rotulo' => $d['rotulo'], 'possui_codigo' => $d['codigo']])->values()]);
    }

    public function index(Request $r, string $tipo): JsonResponse
    {
        $this->exigirGestao();
        $def = $this->refs->definicao($tipo);
        $f = $r->validate(['q' => ['nullable', 'string', 'max:80'], 'ativo' => ['nullable', 'boolean']]);
        $q = $def['modelo']::query()->select('*')->selectRaw('('.$this->refs->subconsultaUso($tipo).') as em_uso');
        if ($tipo === 'tipos-material') {
            $q->with('categoria:id,nome');
        }
        if (! empty($f['q'])) {
            $q->where(fn ($w) => $w->where('nome', 'like', $this->like($f['q']))->when($def['codigo'], fn ($w2) => $w2->orWhere('codigo', 'like', $this->like($f['q']))));
        }
        if ($r->has('ativo')) {
            $q->where('ativo', $r->boolean('ativo'));
        }

        return $this->paginar($r, $q, fn ($m) => $this->dto($m, $tipo), ['id', 'nome'], 'nome');
    }

    public function store(Request $r, string $tipo): JsonResponse
    {
        $this->exigirGestao();
        $def = $this->refs->definicao($tipo);
        $d = $r->validate($this->regras($tipo, $def, null));
        $m = DB::transaction(function () use ($def, $d, $tipo) {
            $m = $def['modelo']::create($d + ['ativo' => true]);
            $this->auditoria()->registrar("referencia.$tipo.criada", 'SUCESSO', $tipo, $m->id, dados: $d);
            if ($tipo === 'categorias') {
                // CP-12: toda categoria nasce com o tipo "Geral" (sem campos obrigatórios extras).
                $g = TipoMaterial::create(['categoria_id' => $m->id, 'nome' => 'Geral', 'perfil' => 'GERAL', 'ativo' => true]);
                $this->auditoria()->registrar('referencia.tipos-material.criada', 'SUCESSO', 'tipos-material', $g->id, dados: ['categoria_id' => $m->id, 'nome' => 'Geral', 'perfil' => 'GERAL']);
            }

            return $m;
        });

        return response()->json($this->dto($m->fresh(), $tipo), 201);
    }

    public function update(Request $r, string $tipo, int $id): JsonResponse
    {
        $this->exigirGestao();
        $def = $this->refs->definicao($tipo);
        $m = $def['modelo']::findOrFail($id);
        $d = $r->validate($this->regras($tipo, $def, $m->id, parcial: true));
        $usos = $this->refs->usos($tipo, $m->id);
        foreach ($def['imutavel_em_uso'] as $campo) {
            if ($usos > 0 && array_key_exists($campo, $d) && $d[$campo] !== $m->{$campo}) {
                throw new SigarbException('VALOR_EM_USO', "O campo \"$campo\" não pode mudar: o valor é usado por $usos registro(s).", 409, ['em_uso' => $usos]);
            }
        }
        $antes = $m->only(array_keys($d));
        DB::transaction(function () use ($m, $d, $antes, $tipo) {
            $m->update($d);
            $this->auditoria()->registrar("referencia.$tipo.atualizada", 'SUCESSO', $tipo, $m->id, dados: ['antes' => $antes, 'depois' => $d]);
        });

        return response()->json($this->dto($m->fresh(), $tipo));
    }

    public function desativar(Request $r, string $tipo, int $id): JsonResponse
    {
        return $this->situacao($r, $tipo, $id, false);
    }

    public function reativar(Request $r, string $tipo, int $id): JsonResponse
    {
        return $this->situacao($r, $tipo, $id, true);
    }

    private function situacao(Request $r, string $tipo, int $id, bool $ativo): JsonResponse
    {
        $this->exigirGestao();
        $def = $this->refs->definicao($tipo);
        $m = $def['modelo']::findOrFail($id);
        $just = $r->validate(['justificativa' => ['required', 'string', 'min:10', 'max:500']])['justificativa'];
        if ($m->ativo === $ativo) {
            throw new SigarbException('SITUACAO_INALTERADA', 'O valor já está nesta situação.', 409);
        }
        DB::transaction(function () use ($m, $ativo, $just, $tipo) {
            $m->update(['ativo' => $ativo, 'desativado_em' => $ativo ? null : now()]);
            $this->auditoria()->registrar("referencia.$tipo.".($ativo ? 'reativada' : 'desativada'), 'SUCESSO', $tipo, $m->id,
                dados: ['justificativa' => $just, 'em_uso' => $this->refs->usos($tipo, $m->id)]);
        });

        return response()->json($this->dto($m->fresh(), $tipo));
    }

    private function regras(string $tipo, array $def, ?int $id, bool $parcial = false): array
    {
        $req = $parcial ? 'sometimes' : 'required';
        if ($tipo === 'tipos-material') {
            // Nome único dentro da categoria (UNIQUE categoria_id+nome no banco).
            $cat = request()->input('categoria_id') ?? ($id ? TipoMaterial::whereKey($id)->value('categoria_id') : null);

            return [
                'categoria_id' => [$req, 'integer', Rule::exists('categorias', 'id')->where('ativo', true)],
                'nome' => [$req, 'string', 'min:2', 'max:80', Rule::unique('tipos_material', 'nome')->where('categoria_id', $cat)->ignore($id)],
                'descricao' => ['nullable', 'string', 'max:255'],
                'perfil' => [$req, Rule::in(PerfilMaterial::perfis())],
                'ativo' => ['prohibited'], 'id' => ['prohibited'],
            ];
        }
        $r = ['nome' => [$req, 'string', 'min:2', 'max:'.($tipo === 'calibres' ? 40 : 120), Rule::unique($def['tabela'], 'nome')->ignore($id)]];
        if ($def['codigo']) {
            $r['codigo'] = [$req, 'regex:/^[A-Z0-9_-]{2,30}$/', Rule::unique($def['tabela'], 'codigo')->ignore($id)];
        }
        if ($tipo === 'categorias') {
            $r['natureza'] = [$req, Rule::in(['ARMAMENTO', 'MUNICAO', 'EQUIPAMENTO', 'PRODUTO', 'TREINAMENTO'])];
        }
        $r['ativo'] = ['prohibited'];
        $r['id'] = ['prohibited'];

        return $r;
    }

    private function dto($m, string $tipo): array
    {
        $def = ReferenciaService::LISTAS[$tipo];
        $out = ['id' => $m->id, 'nome' => $m->nome, 'ativo' => (bool) $m->ativo, 'desativado_em' => $m->desativado_em?->toIso8601String(),
            'em_uso' => isset($m->em_uso) ? (int) $m->em_uso : $this->refs->usos($tipo, $m->id)];
        if ($def['codigo']) {
            $out['codigo'] = $m->codigo;
        }
        if ($tipo === 'categorias') {
            $out['natureza'] = $m->natureza;
        }
        if ($tipo === 'tipos-material') {
            $out += ['categoria' => ['id' => $m->categoria_id, 'nome' => $m->categoria?->nome], 'perfil' => $m->perfil, 'descricao' => $m->descricao];
        }

        return $out;
    }
}
