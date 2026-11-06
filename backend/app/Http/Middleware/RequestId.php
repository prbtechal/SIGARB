<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Correlação requisição ↔ auditoria ↔ log, e cabeçalhos de segurança da API. */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::ulid();
        $request->attributes->set('request_id', $id);
        // Correlação: todo log desta requisição (inclusive erros) leva o request_id.
        \Illuminate\Support\Facades\Log::shareContext(['request_id' => $id]);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        if ($request->is('api/*')) {
            if (! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }
            // Respostas da API nunca são renderizáveis; downloads definem a própria CSP (sandbox).
            if (! $response->headers->has('Content-Security-Policy')) {
                $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
            }
        }

        return $response;
    }
}
