<?php

namespace App\Policies;

use App\Models\LocalEstoque;
use App\Models\Movimentacao;
use App\Models\Usuario;
use App\Services\AutorizacaoService;

class MovimentacaoPolicy extends Escopo
{
    /** Lista do razão (telas Movimentações e Operações e pendências). O detalhe de uma movimentação continua com estoque.ver. */
    public function viewAny(Usuario $u): bool
    {
        return $this->pode($u, 'movimentacoes.ver') && $this->pode($u, 'estoque.ver');
    }

    public function view(Usuario $u, Movimentacao $m): bool
    {
        // Transferência é visível à origem e ao destino. Depósito só para a gestão (estoque.depositos, 2026-11-18).
        $authz = app(AutorizacaoService::class);

        return collect([$m->local_origem_id, $m->local_destino_id])->filter()
            ->contains(fn ($l) => $authz->localVisivel($u, (int) $l) && $this->pode($u, 'estoque.ver', LocalEstoque::whereKey($l)->value('unidade_id')));
    }

    public function estornar(Usuario $u, Movimentacao $m): bool
    {
        return $this->pode($u, 'estoque.estornar', $this->unidade($m))
            && app(AutorizacaoService::class)->localVisivel($u, $m->local_destino_id ?? $m->local_origem_id);
    }

    private function unidade(Movimentacao $m): ?int
    {
        return LocalEstoque::whereKey($m->local_destino_id ?? $m->local_origem_id)->value('unidade_id');
    }
}
