<?php

namespace App\Services;

use App\Models\Unidade;
use App\Models\Usuario;
use App\Support\SigarbException;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Permissão efetiva = papel com a permissão E alvo dentro da subárvore da unidade atribuída (ADR-004, RF-003, RN-024).
 * Atribuição com unidade NULL = escopo global.
 */
class AutorizacaoService
{
    /** @var array<int, array<string, list<string|null>>> usuario_id => permissao => caminhos (null = global) */
    private array $cache = [];

    /** @var array<int, list<int>> usuario_id => ids de locais tipo DEPOSITO ocultos ao usuário */
    private array $ocultos = [];

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
            // P-06 (CP-14): delegação vencida não concede nada.
            ->where(fn ($q) => $q->whereNull('a.expira_em')->orWhere('a.expira_em', '>', now()))
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
        $this->ocultos = [];
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

    /** Papel ativo (não vencido) na unidade ou numa superior; atribuição sem unidade vale em todas. */
    public function temPapel(Usuario $u, array $codigos, int $unidadeId): bool
    {
        $alvo = Unidade::whereKey($unidadeId)->value('caminho');

        return $u->ativo && DB::table('atribuicoes_papel as a')
            ->join('papeis as p', 'p.id', '=', 'a.papel_id')
            ->leftJoin('unidades as un', 'un.id', '=', 'a.unidade_id')
            ->where('a.usuario_id', $u->id)->whereIn('p.codigo', $codigos)
            ->where(fn ($q) => $q->whereNull('a.expira_em')->orWhere('a.expira_em', '>', now()))
            ->get(['a.unidade_id', 'un.caminho'])
            ->contains(fn ($l) => $l->unidade_id === null || ($alvo !== null && str_starts_with($alvo, $l->caminho)));
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

    /** Alguma das permissões (na unidade, se dada). */
    public function podeAlguma(Usuario $u, array $perms, Unidade|int|null $unidade = null): bool
    {
        foreach ($perms as $p) {
            if ($this->pode($u, $p, $unidade)) {
                return true;
            }
        }

        return false;
    }

    /** União dos escopos de várias permissões; null = global em alguma delas. */
    public function unidadesPermitidasAlguma(Usuario $u, array $perms): ?array
    {
        $ids = [];
        foreach ($perms as $p) {
            $x = $this->unidadesPermitidas($u, $p);
            if ($x === null) {
                return null;
            }
            $ids = array_merge($ids, $x);
        }

        return array_values(array_unique($ids));
    }

    /** Escopo pela união de várias permissões (ex.: listas de seleção usadas por quem opera o estoque). */
    public function escoparAlguma(Builder $q, Usuario $u, array $perms, string $coluna = 'unidade_id'): Builder
    {
        $ids = $this->unidadesPermitidasAlguma($u, $perms);

        return $ids === null ? $q : $q->whereIn($coluna, $ids === [] ? [0] : $ids);
    }

    /** Aplica o filtro de escopo diretamente na query (nunca filtrar no front-end). */
    public function escopar(Builder $q, Usuario $u, string $perm, string $coluna = 'unidade_id'): Builder
    {
        $ids = $this->unidadesPermitidas($u, $perm);

        return $ids === null ? $q : $q->whereIn($coluna, $ids === [] ? [0] : $ids);
    }

    /**
     * Decisão do responsável (2026-11-18): locais do tipo DEPÓSITO (retomada de estoque, conferência e distribuição entre unidades)
     * são da gestão. Quem não tem `estoque.depositos` na unidade do depósito não o vê em lista nenhuma nem opera a partir dele/para ele.
     * Ponto único do filtro: listas usam ocultarDepositos()/locaisVisiveis(); operações usam exigirLocalVisivel().
     *
     * @return list<int> ids dos depósitos ocultos ao usuário
     */
    public function depositosOcultos(Usuario $u): array
    {
        if (isset($this->ocultos[$u->id])) {
            return $this->ocultos[$u->id];
        }
        $q = $this->consultaDepositosOcultos($u);
        if ($q === null) {
            return $this->ocultos[$u->id] = [];
        }

        return $this->ocultos[$u->id] = $q->pluck('l.id')->map(fn ($id) => (int) $id)->all();
    }

    /** Subconsulta dos ids de depósitos fora das subárvores onde o usuário tem estoque.depositos; null = vê todos. */
    private function consultaDepositosOcultos(Usuario $u): ?QueryBuilder
    {
        $caminhos = $this->mapa($u)['estoque.depositos'] ?? [];
        if (in_array(null, $caminhos, true)) {
            return null;
        }
        $q = DB::table('locais_estoque as l')->where('l.tipo', 'DEPOSITO')->select('l.id');
        if ($caminhos !== []) {
            $q->join('unidades as un', 'un.id', '=', 'l.unidade_id')
                ->whereNot(fn ($w) => collect($caminhos)->each(fn ($c) => $w->orWhere('un.caminho', 'like', $c.'%')));
        }

        return $q;
    }

    /** Tira da query os depósitos ocultos ao usuário (coluna = id do local de estoque), como subconsulta (sem ida extra ao banco). */
    public function ocultarDepositos(Builder $q, Usuario $u, string $coluna = 'local_estoque_id'): Builder
    {
        $ocultos = $this->consultaDepositosOcultos($u);

        return $ocultos === null ? $q : $q->whereNotIn($coluna, $ocultos);
    }

    /** Subconsulta de ids de locais de estoque no escopo da permissão, sem os depósitos ocultos ao usuário. */
    public function locaisVisiveis(Usuario $u, string $perm = 'estoque.ver'): Builder
    {
        return $this->ocultarDepositos($this->escopar(DB::table('locais_estoque')->select('id'), $u, $perm), $u, 'id');
    }

    public function localVisivel(Usuario $u, ?int $localId): bool
    {
        return $localId === null || ! in_array($localId, $this->depositosOcultos($u), true);
    }

    /** Operação a partir de/para depósito sem `estoque.depositos` → 403. */
    public function exigirLocalVisivel(Usuario $u, ?int ...$locais): void
    {
        foreach ($locais as $l) {
            if (! $this->localVisivel($u, $l === null ? null : (int) $l)) {
                throw new SigarbException('DEPOSITO_SO_GESTAO', 'Locais do tipo depósito são operados só pela gestão.', 403);
            }
        }
    }
}
