<?php

namespace App\Policies;

use App\Models\Unidade;
use App\Models\Usuario;
use App\Services\AutorizacaoService;

class UnidadePolicy extends Escopo
{
    /** Lista de unidades: tela Unidades e locais (organizacao.ver) ou filtros de quem opera o estoque (estoque.ver, no escopo). */
    public function viewAny(Usuario $u): bool
    {
        return app(AutorizacaoService::class)->podeAlguma($u, ['organizacao.ver', 'estoque.ver']);
    }

    public function view(Usuario $u, Unidade $x): bool
    {
        return $this->pode($u, 'organizacao.ver', $x);
    }

    /** Unidade raiz exige escopo global; subunidade exige gerir a unidade superior. */
    public function create(Usuario $u, ?int $superiorId): bool
    {
        return $superiorId === null
            ? app(AutorizacaoService::class)->global($u, 'organizacao.gerir')
            : $this->pode($u, 'organizacao.gerir', $superiorId);
    }

    public function update(Usuario $u, Unidade $x): bool
    {
        return $this->pode($u, 'organizacao.gerir', $x);
    }
}
