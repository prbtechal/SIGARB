<?php

namespace App\Http\Controllers\Api;

use App\Models\Papel;
use App\Models\Permissao;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Matriz papéis × permissões editável pelo administrador. Toda mudança exige motivo e é auditada. */
class PermissaoController extends ApiController
{
    /** O administrador não pode tirar de si o que permite desfazer o erro (evita trancar o sistema). */
    private const TRAVADAS = ['ADMIN' => ['sistema.configurar', 'usuarios.gerir', 'usuarios.ver']];

    private function exigirAdmin(): void
    {
        Gate::allowIf(fn ($u) => $this->authz()->global($u, 'sistema.configurar'));
    }

    public function index(): JsonResponse
    {
        $this->exigirAdmin();
        $papeis = Papel::with('permissoes:id,codigo')->orderBy('id')->get();

        return response()->json([
            'permissoes' => Permissao::orderBy('codigo')->get(['codigo', 'descricao']),
            'papeis' => $papeis->map(fn (Papel $p) => ['codigo' => $p->codigo, 'nome' => $p->nome, 'descricao' => $p->descricao,
                'permissoes' => $p->permissoes->pluck('codigo')->sort()->values(), 'travadas' => self::TRAVADAS[$p->codigo] ?? []]),
        ]);
    }

    public function update(Request $r, string $papel, string $permissao): JsonResponse
    {
        $this->exigirAdmin();
        $d = $r->validate(['concedida' => ['required', 'boolean'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);
        $pa = Papel::where('codigo', $papel)->firstOrFail();
        $pe = Permissao::where('codigo', $permissao)->firstOrFail();
        if (! $d['concedida'] && in_array($pe->codigo, self::TRAVADAS[$pa->codigo] ?? [], true)) {
            throw new SigarbException('PERMISSAO_TRAVADA', 'Esta permissão não pode ser retirada do administrador: sem ela ninguém conseguiria desfazer a mudança.', 422);
        }
        DB::transaction(function () use ($pa, $pe, $d) {
            $tinha = $pa->permissoes()->whereKey($pe->id)->exists();
            if ($tinha === $d['concedida']) {
                return;
            }
            $d['concedida'] ? $pa->permissoes()->attach($pe->id) : $pa->permissoes()->detach($pe->id);
            $this->auditoria()->registrar('permissao.alterada', 'SUCESSO', 'papel', $pa->id, dados: [
                'papel' => $pa->codigo, 'permissao' => $pe->codigo, 'concedida' => $d['concedida'], 'motivo' => $d['motivo'],
            ]);
        });

        return $this->index();
    }
}
