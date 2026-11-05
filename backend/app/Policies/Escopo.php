<?php

namespace App\Policies;

use App\Models\Usuario;
use App\Services\AutorizacaoService;

/** Base comum: toda Policy delega à mesma regra de permissão + escopo (ADR-004). */
abstract class Escopo
{
    protected function pode(Usuario $u, string $perm, $unidade = null): bool
    {
        return app(AutorizacaoService::class)->pode($u, $perm, $unidade);
    }
}
