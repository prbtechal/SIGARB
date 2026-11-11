<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Totp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** PSI-PRB cap. 11 — TOTP com segredo cifrado (APP_KEY) e códigos de recuperação de uso único (guardados como SHA-256). */
class MfaService
{
    public const CODIGOS_RECUPERACAO = 8;

    /** Código TOTP (sem reuso) ou código de recuperação (consumido). Serializado por conta para não aceitar o mesmo código duas vezes. */
    public function validar(Usuario $u, string $codigo, ?string $segredo = null): bool
    {
        return DB::transaction(function () use ($u, $codigo, $segredo) {
            $atual = Usuario::whereKey($u->id)->lockForUpdate()->firstOrFail();
            $segredo ??= $atual->mfa_segredo;
            if (! $segredo) {
                return false;
            }
            $codigo = preg_replace('/[\s-]/', '', $codigo);
            if ($passo = Totp::verificar($segredo, $codigo, $atual->mfa_ultimo_passo)) {
                $atual->forceFill(['mfa_ultimo_passo' => $passo])->saveQuietly();
                $u->setRawAttributes($atual->getAttributes(), true);

                return true;
            }
            $restantes = $atual->mfa_recuperacao ?? [];
            $h = hash('sha256', Str::upper($codigo));
            if ($atual->mfaAtivo() && in_array($h, $restantes, true)) {
                $atual->forceFill(['mfa_recuperacao' => array_values(array_diff($restantes, [$h]))])->saveQuietly();
                $u->setRawAttributes($atual->getAttributes(), true);

                return true;
            }

            return false;
        });
    }

    /** @return list<string> códigos em texto (mostrados uma única vez) */
    public function gerarCodigosRecuperacao(Usuario $u): array
    {
        $codigos = array_map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)), range(1, self::CODIGOS_RECUPERACAO));
        $u->forceFill(['mfa_recuperacao' => array_map(fn ($c) => hash('sha256', str_replace('-', '', $c)), $codigos)])->saveQuietly();

        return $codigos;
    }

    public function limpar(Usuario $u): void
    {
        $u->forceFill(['mfa_segredo' => null, 'mfa_confirmado_em' => null, 'mfa_ultimo_passo' => null, 'mfa_recuperacao' => null])->saveQuietly();
    }
}
