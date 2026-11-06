<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * RNF-14 — uma linha estruturada por requisição da API (canal "sigarb_json").
 * Registra a rota como MODELO (sem IDs nem query string) e nunca registra corpo, cabeçalhos ou cookies.
 */
class RegistroRequisicao
{
    public function handle(Request $request, Closure $next): Response
    {
        $inicio = hrtime(true);
        $response = $next($request);
        if ($request->is('api/*')) {
            $status = $response->getStatusCode();
            $ctx = [
                'tipo' => 'http',
                'metodo' => $request->method(),
                'rota' => $request->route()?->uri() ?? 'desconhecida',
                'status' => $status,
                'duracao_ms' => round((hrtime(true) - $inicio) / 1e6, 1),
                'usuario_id' => $request->user()?->id,
            ];
            if ($status >= 400) {
                $ctx['erro'] = json_decode((string) $response->getContent(), true)['error']['code'] ?? null; // código estável, sem detalhes
            }
            Log::channel('sigarb_json')->log($status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'), 'requisicao', $ctx);
        }

        return $response;
    }
}
