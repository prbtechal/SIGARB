<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /** Vetores do RFC 6238 (SHA1, segredo ASCII "12345678901234567890"), truncados para 6 dígitos. */
    public function test_vetores_rfc6238(): void
    {
        $s = Totp::base32('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $s);
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $t => $c) {
            $this->assertSame($c, Totp::codigo($s, intdiv($t, 30)));
        }
        $this->assertSame('12345678901234567890', Totp::deBase32($s));
    }

    public function test_janela_e_reuso(): void
    {
        $s = Totp::novoSegredo();
        $t = 1_800_000_000;
        $p = intdiv($t, 30);
        $this->assertSame($p - 1, Totp::verificar($s, Totp::codigo($s, $p - 1), null, $t));
        $this->assertSame($p + 1, Totp::verificar($s, Totp::codigo($s, $p + 1), null, $t));
        $this->assertNull(Totp::verificar($s, Totp::codigo($s, $p + 2), null, $t));
        $this->assertNull(Totp::verificar($s, Totp::codigo($s, $p), $p, $t)); // mesmo código não vale duas vezes
        $this->assertNull(Totp::verificar($s, 'abc123', null, $t));
        $this->assertStringStartsWith('otpauth://totp/SIGARB:Fulano%20de%20Tal?secret='.$s, Totp::uri($s, 'Fulano de Tal', 'SIGARB'));
    }
}
