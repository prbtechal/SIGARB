<?php

namespace App\Support;

/** Estado efetivo do portão de políticas (ADR-006): HABILITADA, PROVISORIA (ligada por configuração) ou BLOQUEADA. */
class Operacoes
{
    public static function estado(string $op): string
    {
        $cfg = config("sigarb.operacoes.$op");
        if (! $cfg || ! empty($cfg['desligada'])) { // desligada pelo Administrador (CP-16)
            return 'BLOQUEADA';
        }
        if ($cfg['estado'] === 'HABILITADA') {
            return 'HABILITADA';
        }

        return isset($cfg['regra_provisoria']) && in_array($op, config('sigarb.operacoes_provisorias', []), true) ? 'PROVISORIA' : 'BLOQUEADA';
    }

    public static function exigir(string $op): void
    {
        if (self::estado($op) === 'BLOQUEADA') {
            throw SigarbException::operacaoBloqueada($op);
        }
    }
}
