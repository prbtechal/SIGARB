<?php

namespace App\Support;

/** CPF como identificador de acesso: só dígitos, com verificação dos dígitos verificadores. */
final class Cpf
{
    public static function digitos(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    public static function valido(string $cpf): bool
    {
        if (! preg_match('/^\d{11}$/', $cpf) || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) {
                $s += (int) $cpf[$i] * ($t + 1 - $i);
            }
            if ((int) $cpf[$t] !== ((10 * $s) % 11) % 10) {
                return false;
            }
        }

        return true;
    }
}
