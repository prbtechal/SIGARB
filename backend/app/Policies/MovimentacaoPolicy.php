<?php

namespace App\Policies;

use App\Models\LocalEstoque;
use App\Models\Movimentacao;
use App\Models\Usuario;

class MovimentacaoPolicy extends Escopo
{
    public function viewAny(Usuario $u): bool { return $this->pode($u, 'estoque.ver'); }

    public function view(Usuario $u, Movimentacao $m): bool
    {
        return $this->pode($u, 'estoque.ver', $this->unidade($m));
    }

    public function estornar(Usuario $u, Movimentacao $m): bool
    {
        return $this->pode($u, 'estoque.estornar', $this->unidade($m));
    }

    private function unidade(Movimentacao $m): ?int
    {
        return LocalEstoque::whereKey($m->local_destino_id ?? $m->local_origem_id)->value('unidade_id');
    }
}
