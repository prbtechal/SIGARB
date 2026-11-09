<?php

namespace App\Http\Controllers\Api;

use App\Support\Ajustes;
use App\Support\SigarbException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** CP-16 — Administrador liga/desliga operações e ajusta políticas e prazos. Toda mudança exige motivo e é auditada. */
class ConfiguracaoController extends ApiController
{
    private function exigirAdmin(): void
    {
        Gate::allowIf(fn ($u) => $this->authz()->global($u, 'sistema.configurar'));
    }

    public function index(): JsonResponse
    {
        $this->exigirAdmin();
        $salvos = DB::table('configuracoes as c')->leftJoin('usuarios as u', 'u.id', '=', 'c.alterado_por')
            ->get(['c.chave', 'c.valor', 'c.motivo', 'c.alterado_em', 'u.nome as alterado_por'])->keyBy('chave');

        return response()->json(['data' => collect(Ajustes::catalogo())->map(function ($d, $k) use ($salvos) {
            $s = $salvos[$k] ?? null;

            return ['chave' => $k, 'valor' => config("sigarb.$k") ?? false, 'padrao' => Ajustes::padrao($k), 'alterado' => $s?->valor !== null,
                'motivo' => $s?->motivo, 'alterado_por' => $s?->alterado_por, 'alterado_em' => $s?->alterado_em] + $d;
        })->values(), 'papeis_autoaprovacao' => Ajustes::PAPEIS_AUTOAPROVACAO]);
    }

    public function update(Request $r, string $chave): JsonResponse
    {
        $this->exigirAdmin();
        $d = $r->validate(['valor' => ['present'], 'motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->gravar($chave, Ajustes::normalizar($this->existente($chave), $d['valor']), $d['motivo']);
    }

    public function restaurar(Request $r, string $chave): JsonResponse
    {
        $this->exigirAdmin();
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->gravar($this->existente($chave), null, $d['motivo']);
    }

    private function existente(string $chave): string
    {
        return isset(Ajustes::catalogo()[$chave]) ? $chave : throw new SigarbException('AJUSTE_DESCONHECIDO', 'Ajuste não encontrado.', 404);
    }

    private function gravar(string $chave, mixed $valor, string $motivo): JsonResponse
    {
        $antes = config("sigarb.$chave") ?? false;
        $depois = $valor ?? Ajustes::padrao($chave);
        DB::transaction(function () use ($chave, $valor, $motivo, $antes, $depois) {
            DB::table('configuracoes')->upsert([['chave' => $chave, 'valor' => $valor === null ? null : json_encode($valor),
                'motivo' => $motivo, 'alterado_por' => auth()->id(), 'alterado_em' => now()]], ['chave']);
            $this->auditoria()->registrar('configuracao.alterada', recursoTipo: 'configuracao', dados: [
                'chave' => $chave, 'antes' => $antes, 'depois' => $depois, 'voltou_ao_padrao' => $valor === null, 'motivo' => $motivo]);
        });
        Ajustes::aplicar();

        return $this->index();
    }
}
