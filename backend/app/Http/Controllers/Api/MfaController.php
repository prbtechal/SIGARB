<?php

namespace App\Http\Controllers\Api;

use App\Models\Usuario;
use App\Services\DispositivoService;
use App\Services\MfaService;
use App\Support\SigarbException;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** PSI-PRB cap. 11 — verificação em duas etapas por aplicativo autenticador (TOTP). */
class MfaController extends ApiController
{
    public function __construct(private MfaService $mfa) {}

    /** Gera (ou regenera, enquanto não confirmado) a chave para o aplicativo. */
    public function iniciar(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u->mfaAtivo()) {
            throw new SigarbException('MFA_JA_ATIVO', 'A verificação em duas etapas já está ativa nesta conta.', 422);
        }
        $segredo = Totp::novoSegredo();
        $u->forceFill(['mfa_segredo' => $segredo, 'mfa_ultimo_passo' => null])->saveQuietly();
        $this->auditoria()->registrar('auth.mfa.cadastro_iniciado', 'SUCESSO', 'usuario', $u->id);

        return response()->json(['segredo' => $segredo, 'uri' => Totp::uri($segredo, $u->nome, config('sigarb.mfa.emissor'))]);
    }

    public function confirmar(Request $r): JsonResponse
    {
        $d = $r->validate(['codigo' => ['required', 'string', 'max:20']]);
        $u = $r->user();
        if ($u->mfaAtivo() || ! $u->mfa_segredo) {
            throw new SigarbException('MFA_SEM_CADASTRO', 'Gere a chave antes de confirmar.', 422);
        }
        if (! preg_match('/^\d{6}$/', trim($d['codigo'])) || ! $this->mfa->validar($u, trim($d['codigo']))) {
            $this->auditoria()->registrar('auth.mfa.cadastrado', 'FALHA', 'usuario', $u->id);
            throw new SigarbException('CODIGO_INVALIDO', 'Código inválido. Confira se a chave foi digitada certo e se o relógio do celular está correto.', 422);
        }
        $u->forceFill(['mfa_confirmado_em' => now()])->saveQuietly();
        $codigos = $this->mfa->gerarCodigosRecuperacao($u);
        $this->auditoria()->registrar('auth.mfa.cadastrado', 'SUCESSO', 'usuario', $u->id);

        return response()->json(['codigos_recuperacao' => $codigos, 'perfil' => app(AuthController::class)->perfil($u)]);
    }

    /** A própria pessoa desliga (com um código válido), salvo se o papel exigir MFA. */
    public function desativar(Request $r): JsonResponse
    {
        $d = $r->validate(['codigo' => ['required', 'string', 'max:20']]);
        $u = $r->user();
        if ($u->exigeMfa()) {
            throw new SigarbException('MFA_OBRIGATORIO', 'O seu perfil exige a verificação em duas etapas.', 422);
        }
        if (! $u->mfaAtivo() || ! $this->mfa->validar($u, $d['codigo'])) {
            $this->auditoria()->registrar('auth.mfa.desativado', 'FALHA', 'usuario', $u->id);
            throw new SigarbException('CODIGO_INVALIDO', 'Código inválido ou já usado.', 422);
        }
        $this->mfa->limpar($u);
        $this->auditoria()->registrar('auth.mfa.desativado', 'SUCESSO', 'usuario', $u->id);

        return response()->json(app(AuthController::class)->perfil($u));
    }

    /** Gestor redefine o MFA de outra pessoa (celular perdido etc.): desliga e derruba as sessões. */
    public function redefinir(Request $r, Usuario $usuario): JsonResponse
    {
        Gate::authorize('alterarAcesso', $usuario);
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:300']]);
        $this->mfa->limpar($usuario);
        DB::table('sessions')->where('user_id', $usuario->id)->delete();
        app(DispositivoService::class)->revogarTodos($usuario->id);
        $this->auditoria()->registrar('usuario.mfa_redefinido', 'SUCESSO', 'usuario', $usuario->id, dados: ['motivo' => $d['motivo']]);

        return response()->json(null, 204);
    }
}
