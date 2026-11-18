<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\Usuario;

class MaterialPolicy extends Escopo
{
    /** Catálogo (catalogo.ver) ou lista de seleção/filtro de quem opera o estoque (estoque.ver). A tela Catálogo exige catalogo.ver. */
    public function viewAny(Usuario $u): bool
    {
        return $this->pode($u, 'catalogo.ver') || $this->pode($u, 'estoque.ver');
    }

    public function view(Usuario $u, Material $m): bool
    {
        return $this->pode($u, 'catalogo.ver') || $this->pode($u, 'estoque.ver');
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
