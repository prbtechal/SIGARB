<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Busca textual do balcão (decisão 2026-11-18): parcial, sem diferença de maiúsculas e acentos (collation *_ci do banco),
 * palavra por palavra (todas precisam casar, cada uma em qualquer coluna) e tolerante a plural simples
 * ("munições" acha "Munição", "coletes" acha "Colete").
 */
class Busca
{
    /** @return list<string> radicais das palavras do termo, sem curingas de LIKE */
    public static function termos(string $t): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            $out[] = self::radical($p);
        }

        return array_values(array_unique(array_filter($out, fn ($p) => $p !== '')));
    }

    /** "munições"/"munição" → "muniç"; "coletes" → "colete"; palavras curtas ficam como estão. */
    public static function radical(string $p): string
    {
        $p = mb_strtolower($p);
        if (mb_strlen($p) > 4 && preg_match('/^(.+?)(ões|oes|ães|aes|ão|ao)$/u', $p, $m)) {
            return $m[1];
        }
        if (mb_strlen($p) > 4 && preg_match('/^(.+[^s])s$/u', $p, $m)) {
            return $m[1];
        }

        return $p;
    }

    public static function like(string $p): string
    {
        return '%'.addcslashes($p, '%_\\').'%';
    }

    /** Toda palavra precisa casar em pelo menos uma das colunas. Sem palavras, não filtra. */
    public static function aplicar(Builder $q, string $t, array $colunas): void
    {
        foreach (self::termos($t) as $p) {
            $q->where(function ($w) use ($p, $colunas) {
                foreach ($colunas as $c) {
                    $w->orWhere($c, 'like', self::like($p));
                }
            });
        }
    }
}
