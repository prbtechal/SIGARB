<?php

namespace App\Support;

/** TOTP (RFC 6238, HMAC-SHA1, 6 dígitos, passo de 30 s) sem dependência externa. PSI-PRB cap. 11. */
final class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function novoSegredo(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($b) => self::B32[bindec(str_pad($b, 5, '0'))], str_split($bits, 5)));
    }

    public static function deBase32(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn ($b) => chr(bindec($b)), array_filter(str_split($bits, 8), fn ($b) => strlen($b) === 8)));
    }

    public static function codigo(string $segredoBase32, int $passo, int $digitos = 6): string
    {
        $h = hash_hmac('sha1', pack('J', $passo), self::deBase32($segredoBase32), true);
        $o = ord($h[19]) & 0xF;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

        return str_pad((string) ($n % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);
    }

    /** Janela de ±1 passo. Devolve o passo aceito (só se for maior que o último usado) ou null. */
    public static function verificar(string $segredoBase32, string $codigo, ?int $ultimoPasso = null, ?int $agora = null): ?int
    {
        if (! preg_match('/^\d{6}$/', $codigo)) {
            return null;
        }
        $atual = intdiv($agora ?? time(), 30);
        foreach ([$atual - 1, $atual, $atual + 1] as $p) {
            if (($ultimoPasso === null || $p > $ultimoPasso) && hash_equals(self::codigo($segredoBase32, $p), $codigo)) {
                return $p;
            }
        }

        return null;
    }

    public static function uri(string $segredoBase32, string $conta, string $emissor): string
    {
        return 'otpauth://totp/'.rawurlencode($emissor).':'.rawurlencode($conta).'?'.http_build_query(
            ['secret' => $segredoBase32, 'issuer' => $emissor, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30], '', '&', PHP_QUERY_RFC3986);
    }
}
