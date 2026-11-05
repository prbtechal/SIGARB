<?php

namespace App\Policies;

use App\Models\Usuario;
use App\Services\AutorizacaoService;

/** Gestão de contas exige escopo global (proposta; P-02/P-06). */
class UsuarioPolicy
{
    public function viewAny(Usuario $u): bool { return app(AutorizacaoService::class)->global($u, 'usuarios.ver'); }
    public function view(Usuario $u, Usuario $alvo): bool { return $this->viewAny($u); }
    public function create(Usuario $u): bool { return app(AutorizacaoService::class)->global($u, 'usuarios.gerir'); }
    public function update(Usuario $u, Usuario $alvo): bool { return $this->create($u); }
    /** Segregação: ninguém altera os próprios papéis nem desativa a si mesmo. */
    public function alterarAcesso(Usuario $u, Usuario $alvo): bool { return $this->create($u) && $u->id !== $alvo->id; }
}
