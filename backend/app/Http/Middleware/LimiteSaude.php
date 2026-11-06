<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limite de taxa das sondas de saúde (RNF-09) em store LOCAL (padrão: file), independente do banco.
 * Saúde é por nó; o limite por nó é a semântica correta. Login e demais rotas continuam no throttle padrão.
 */
class LimiteSaude
{
    public function handle(Request $request, Closure $next, int $porMinuto = 30): Response
    {
        $limitador = new RateLimiter(Cache::store(config('sigarb.saude.cache_store')));
        $chave = 'sigarb:saude:limite:'.sha1($request->path().'|'.$request->ip());
        if ($limitador->tooManyAttempts($chave, $porMinuto)) {
            return response()->json(['error' => ['code' => 'LIMITE_TAXA', 'message' => 'Muitas requisições.']], 429)
                ->header('Retry-After', (string) $limitador->availableIn($chave));
        }
        $limitador->hit($chave, 60);

        return $next($request);
    }
}
