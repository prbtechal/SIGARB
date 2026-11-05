<?php

namespace App\Services;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/** Dispositivos confiáveis registrados no servidor: revogáveis, rotacionados e com limite próprio de falhas. */
class DispositivoService
{
    public const COOKIE = 'sigarb_dispositivo';
    private const FALHAS_MAX = 5;   // por dispositivo, janela de 15 min
    private const VALIDADE_DIAS = 90;

    /** Retorna o hash do token se o cookie corresponde a dispositivo ativo do usuário. */
    public function confiavel(Request $r, Usuario $u): ?string
    {
        $token = (string) $r->cookie(self::COOKIE);
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $reg = DB::table('dispositivos_confiaveis')->where('token_hash', $hash)->whereNull('revogado_em')
            ->where('usado_em', '>=', now()->subDays(self::VALIDADE_DIAS))->first();
        if (! $reg || ! hash_equals((string) $reg->usuario_id, (string) $u->id)) {
            return null;
        }
        if (RateLimiter::tooManyAttempts($this->chaveFalhas($hash), self::FALHAS_MAX)) {
            return null; // cookie vazado não serve para tentativas ilimitadas
        }

        return $hash;
    }

    public function registrarFalha(?string $hash): void
    {
        if ($hash) {
            RateLimiter::hit($this->chaveFalhas($hash), 900);
        }
    }

    /** Rotaciona: revoga o token apresentado (se houver) e emite um novo. */
    public function emitir(Request $r, Usuario $u): Cookie
    {
        $antigo = (string) $r->cookie(self::COOKIE);
        if ($antigo !== '') {
            DB::table('dispositivos_confiaveis')->where('token_hash', hash('sha256', $antigo))->where('usuario_id', $u->id)
                ->whereNull('revogado_em')->update(['revogado_em' => now()]);
        }
        $token = Str::random(64);
        DB::table('dispositivos_confiaveis')->insert(['usuario_id' => $u->id, 'token_hash' => hash('sha256', $token), 'criado_em' => now(), 'usado_em' => now()]);

        return cookie(self::COOKIE, $token, 60 * 24 * self::VALIDADE_DIAS, httpOnly: true, sameSite: 'lax');
    }

    public function revogarTodos(int $usuarioId): int
    {
        return DB::table('dispositivos_confiaveis')->where('usuario_id', $usuarioId)->whereNull('revogado_em')->update(['revogado_em' => now()]);
    }

    private function chaveFalhas(string $hash): string
    {
        return 'login-disp:'.$hash;
    }
}
