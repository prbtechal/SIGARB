<?php

namespace App\Http\Middleware;

use App\Support\SigarbException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Com senha padrão/redefinida, a conta só pode ver o próprio perfil, trocar a senha ou sair. */
class SenhaProvisoria
{
    private const LIBERADAS = ['api/v1/me', 'api/v1/me/password', 'api/v1/auth/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->senha_provisoria && ! in_array($request->route()?->uri(), self::LIBERADAS, true)) {
            throw new SigarbException('SENHA_PROVISORIA', 'Troque a senha padrão antes de usar o sistema.', 403);
        }

        return $next($request);
    }
}
