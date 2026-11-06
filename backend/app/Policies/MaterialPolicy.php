<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\Usuario;

class MaterialPolicy extends Escopo
{
    public function viewAny(Usuario $u): bool
    {
        return $this->pode($u, 'catalogo.ver');
    }

    public function view(Usuario $u, Material $m): bool
    {
        return $this->pode($u, 'catalogo.ver');
    }

    public function create(Usuario $u): bool
    {
        return $this->pode($u, 'catalogo.gerir');
    }

    public function update(Usuario $u, Material $m): bool
    {
        return $this->pode($u, 'catalogo.gerir');
    }
}
