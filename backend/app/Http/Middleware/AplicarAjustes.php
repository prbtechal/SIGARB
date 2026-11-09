<?php

namespace App\Http\Middleware;

use App\Support\Ajustes;
use Closure;
use Illuminate\Http\Request;

/** CP-16: valores salvos pelo Administrador valem a partir da próxima requisição. */
class AplicarAjustes
{
    public function handle(Request $request, Closure $next)
    {
        Ajustes::aplicar();

        return $next($request);
    }
}
