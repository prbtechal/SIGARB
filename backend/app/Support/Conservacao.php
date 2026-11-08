<?php

namespace App\Support;

/**
 * Estado de conservação (condição física). Lista única do sistema (CP-12).
 * Não confundir com a situação operacional (itens_individuais.situacao), que só muda por movimentação.
 * Valores antigos gravados (NOVO/BOM/REGULAR/RUIM) continuam válidos: são subconjunto desta lista.
 */
final class Conservacao
{
    public const VALORES = ['NOVO', 'BOM', 'REGULAR', 'RUIM', 'DANIFICADO', 'INSERVIVEL'];

    public static function regra(): string
    {
        return 'in:'.implode(',', self::VALORES);
    }
}
