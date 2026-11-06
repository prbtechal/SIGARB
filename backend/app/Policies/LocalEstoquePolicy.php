<?php

namespace App\Policies;

use App\Models\LocalEstoque;
use App\Models\Usuario;

class LocalEstoquePolicy extends Escopo
{
    public function viewAny(Usuario $u): bool
    {
        return $this->pode($u, 'organizacao.ver');
    }

    public function create(Usuario $u, int $unidadeId): bool
    {
        return $this->pode($u, 'organizacao.gerir', $unidadeId);
    }

    public function update(Usuario $u, LocalEstoque $l): bool
    {
        return $this->pode($u, 'organizacao.gerir', $l->unidade_id);
    }

    public function registrarEntrada(Usuario $u, LocalEstoque $l): bool
    {
        return $this->pode($u, 'estoque.entrada', $l->unidade_id);
    }
}
