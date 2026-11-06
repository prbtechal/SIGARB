<?php

use App\Http\Middleware\RequestId;
use App\Services\AuditoriaService;
use App\Support\SigarbException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$erro = fn (string $codigo, string $mensagem, int $status, array $extra = []) => response()->json(
    ['error' => array_filter(['code' => $codigo, 'message' => $mensagem, 'request_id' => request()->attributes->get('request_id')] + $extra, fn ($v) => $v !== null && $v !== [])],
    $status
);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::prefix('api/v1')->group(base_path('routes/saude.php')); // sem grupo web (sem sessão/CSRF)
            Route::middleware('web')->prefix('api/v1')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->append(\App\Http\Middleware\RegistroRequisicao::class);
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($erro): void {
        $exceptions->dontReport([SigarbException::class]);
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());

        $exceptions->render(fn (SigarbException $e) => $erro($e->codigo, $e->getMessage(), $e->status, ['details' => $e->detalhes]));
        $exceptions->render(fn (ValidationException $e, Request $r) => $r->is('api/*')
            ? $erro('VALIDACAO', 'Dados inválidos. Verifique os campos indicados.', 422, ['fields' => $e->errors()]) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $r->is('api/*')
            ? $erro('NAO_AUTENTICADO', 'Sessão inexistente ou expirada.', 401) : null);
        $exceptions->render(function (AccessDeniedHttpException $e, Request $r) use ($erro) {
            if (! $r->is('api/*')) {
                return null;
            }
            if ($r->user()) {
                app(AuditoriaService::class)->registrar('acesso.negado', 'NEGADO', dados: ['metodo' => $r->method(), 'rota' => $r->route()?->uri()]);
            }

            return $erro('ACESSO_NEGADO', 'Você não tem permissão para esta ação neste escopo.', 403);
        });
        $exceptions->render(fn (NotFoundHttpException $e, Request $r) => $r->is('api/*') ? $erro('NAO_ENCONTRADO', 'Recurso não encontrado.', 404) : null);
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($erro) {
            if (! $r->is('api/*')) {
                return null;
            }

            return match ($e->getStatusCode()) {
                413 => $erro('ARQUIVO_GRANDE_DEMAIS', 'A requisição excede o tamanho máximo aceito.', 413),
                419 => $erro('CSRF_INVALIDO', 'Token de segurança expirado. Recarregue a página.', 419),
                429 => $erro('MUITAS_TENTATIVAS', 'Muitas requisições. Aguarde e tente novamente.', 429),
                405 => $erro('METODO_NAO_PERMITIDO', 'Método não permitido.', 405),
                default => $erro('ERRO_HTTP', 'Requisição não pôde ser atendida.', $e->getStatusCode()),
            };
        });
        $exceptions->render(fn (\Throwable $e, Request $r) => $r->is('api/*') && ! config('app.debug')
            ? $erro('ERRO_INTERNO', 'Erro interno. Informe o request_id ao suporte.', 500) : null);
    })->create();
