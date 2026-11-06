<?php

namespace App\Policies;

use App\Models\Militar;
use App\Models\Usuario;

class MilitarPolicy extends Escopo
{
    public function viewAny(Usuario $u): bool
    {
        return $this->pode($u, 'militares.ver');
    }

    public function view(Usuario $u, Militar $m): bool
    {
        return $this->pode($u, 'militares.ver', $m->unidade_id);
    }

    public function create(Usuario $u, int $unidadeId): bool
    {
        return $this->pode($u, 'militares.gerir', $unidadeId);
    }

    public function update(Usuario $u, Militar $m, ?int $novaUnidadeId = null): bool
    {
        return $this->pode($u, 'militares.gerir', $m->unidade_id)
            && ($novaUnidadeId === null || $this->pode($u, 'militares.gerir', $novaUnidadeId));
    }
}
