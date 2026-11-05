<?php

namespace App\Http\Middleware;

use App\Support\SigarbException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Conta desativada perde acesso imediatamente, mesmo com sessão viva (RF-004). */
class UsuarioAtivo
{
    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        if ($u && ! $u->ativo) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            throw new SigarbException('CONTA_INATIVA', 'Conta desativada.', 401);
        }

        return $next($request);
    }
}
