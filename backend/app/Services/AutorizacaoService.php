<?php

namespace App\Services;

use App\Models\Unidade;
use App\Models\Usuario;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Permissão efetiva = papel com a permissão E alvo dentro da subárvore da unidade atribuída (ADR-004, RF-003, RN-024).
 * Atribuição com unidade NULL = escopo global.
 */
class AutorizacaoService
{
    /** @var array<int, array<string, list<string|null>>> usuario_id => permissao => caminhos (null = global) */
    private array $cache = [];

    /** @return array<string, list<string|null>> */
    public function mapa(Usuario $u): array
    {
        if (isset($this->cache[$u->id])) {
            return $this->cache[$u->id];
        }
        $linhas = DB::table('atribuicoes_papel as a')
            ->join('papel_permissao as pp', 'pp.papel_id', '=', 'a.papel_id')
            ->join('permissoes as p', 'p.id', '=', 'pp.permissao_id')
            ->leftJoin('unidades as un', 'un.id', '=', 'a.unidade_id')
            ->where('a.usuario_id', $u->id)
            ->get(['p.codigo', 'a.unidade_id', 'un.caminho']);
        $mapa = [];
        foreach ($linhas as $l) {
            $mapa[$l->codigo][] = $l->unidade_id === null ? null : $l->caminho;
        }

        return $this->cache[$u->id] = $mapa;
    }

    public function limpar(): void
    {
        $this->cache = [];
    }

    /** @return list<string> */
    public function permissoes(Usuario $u): array
    {
        return array_keys($this->mapa($u));
    }

    public function global(Usuario $u, string $perm): bool
    {
        return in_array(null, $this->mapa($u)[$perm] ?? [], true);
    }

    /** Sem unidade: basta possuir a permissão em algum escopo. */
    public function pode(Usuario $u, string $perm, Unidade|int|null $unidade = null): bool
    {
        if (! $u->ativo) {
            return false;
        }
        $caminhos = $this->mapa($u)[$perm] ?? [];
        if ($caminhos === []) {
            return false;
        }
        if ($unidade === null || in_array(null, $caminhos, true)) {
            return true;
        }
        $alvo = $unidade instanceof Unidade ? $unidade->caminho : Unidade::whereKey($unidade)->value('caminho');
        foreach ($caminhos as $c) {
            if ($alvo !== null && str_starts_with($alvo, $c)) {
                return true;
            }
        }

        return false;
    }

    /** IDs de unidades acessíveis para a permissão; null = sem restrição (global). */
    public function unidadesPermitidas(Usuario $u, string $perm): ?array
    {
        $caminhos = $this->mapa($u)[$perm] ?? [];
        if (in_array(null, $caminhos, true)) {
            return null;
        }
        if ($caminhos === []) {
            return [];
        }

        return Unidade::query()->where(function ($q) use ($caminhos) {
            foreach ($caminhos as $c) {
                $q->orWhere('caminho', 'like', $c.'%');
            }
        })->pluck('id')->all();
    }

    /** Aplica o filtro de escopo diretamente na query (nunca filtrar no front-end). */
    public function escopar(Builder $q, Usuario $u, string $perm, string $coluna = 'unidade_id'): Builder
    {
        $ids = $this->unidadesPermitidas($u, $perm);

        return $ids === null ? $q : $q->whereIn($coluna, $ids === [] ? [0] : $ids);
    }
}
