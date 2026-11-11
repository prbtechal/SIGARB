<?php

namespace App\Http\Middleware;

use App\Support\SigarbException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conta com pendência de acesso só vê o próprio perfil, resolve a pendência ou sai:
 * senha padrão/redefinida (SENHA_PROVISORIA), senha vencida (SENHA_EXPIRADA, PSI-PRB cap. 10) e MFA exigido pelo papel
 * e ainda não cadastrado (MFA_CADASTRO_OBRIGATORIO, PSI-PRB cap. 11 — só quando configurado).
 */
class PendenciaAcesso
{
    private const LIBERADAS = ['api/v1/me', 'api/v1/me/password', 'api/v1/auth/logout'];

    private const MFA = ['api/v1/me/mfa/setup', 'api/v1/me/mfa/confirm'];

    public function handle(Request $request, Closure $next): Response
    {
        $u = $request->user();
        $rota = $request->route()?->uri();
        if (! $u || in_array($rota, self::LIBERADAS, true)) {
            return $next($request);
        }
        if ($u->senha_provisoria) {
            throw new SigarbException('SENHA_PROVISORIA', 'Troque a senha provisória antes de usar o sistema.', 403);
        }
        if ($u->senhaExpirada()) {
            throw new SigarbException('SENHA_EXPIRADA', 'Sua senha expirou (troca obrigatória a cada '.config('sigarb.senha.validade_dias').' dias). Crie uma nova senha para continuar.', 403);
        }
        if (! in_array($rota, self::MFA, true) && ! $u->mfaAtivo() && $u->exigeMfa()) {
            throw new SigarbException('MFA_CADASTRO_OBRIGATORIO', 'O seu perfil exige a verificação em duas etapas. Cadastre o aplicativo autenticador para continuar.', 403);
        }

        return $next($request);
    }
}
