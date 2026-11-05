<?php

namespace App\Policies;

use App\Models\Unidade;
use App\Models\Usuario;

class UnidadePolicy extends Escopo
{
    public function viewAny(Usuario $u): bool { return $this->pode($u, 'organizacao.ver'); }
    public function view(Usuario $u, Unidade $x): bool { return $this->pode($u, 'organizacao.ver', $x); }
    /** Unidade raiz exige escopo global; subunidade exige gerir a unidade superior. */
    public function create(Usuario $u, ?int $superiorId): bool
    {
        return $superiorId === null
            ? app(\App\Services\AutorizacaoService::class)->global($u, 'organizacao.gerir')
            : $this->pode($u, 'organizacao.gerir', $superiorId);
    }
    public function update(Usuario $u, Unidade $x): bool { return $this->pode($u, 'organizacao.gerir', $x); }
}
