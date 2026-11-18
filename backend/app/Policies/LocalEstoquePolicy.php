<?php

namespace App\Policies;

use App\Models\LocalEstoque;
use App\Models\Usuario;
use App\Services\AutorizacaoService;

class LocalEstoquePolicy extends Escopo
{
    /** Lista de locais: tela Unidades e locais (organizacao.ver) ou seleção/filtros de quem opera o estoque (estoque.ver, no escopo). */
    public function viewAny(Usuario $u): bool
    {
        return app(AutorizacaoService::class)->podeAlguma($u, ['organizacao.ver', 'estoque.ver']);
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
