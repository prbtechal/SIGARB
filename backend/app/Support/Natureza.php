<?php

namespace App\Support;

/**
 * Natureza da categoria de material: CÓDIGO gravado no banco (enum, inalterado) → rótulo exibido.
 * Decisão do responsável (2026-11-16): EQUIPAMENTO aparece como "EPI" em toda a interface, relatórios e exportações.
 * O valor do enum, os códigos e as categorias não mudam; só a apresentação (mesmo mapa no front-end: NATUREZAS em ui.tsx).
 */
final class Natureza
{
    public const CODIGOS = ['ARMAMENTO', 'MUNICAO', 'EQUIPAMENTO', 'PRODUTO', 'TREINAMENTO'];

    public const ROTULOS = ['ARMAMENTO' => 'Armamento', 'MUNICAO' => 'Munição', 'EQUIPAMENTO' => 'EPI', 'PRODUTO' => 'Produto', 'TREINAMENTO' => 'Treinamento'];

    /** Valor curto para planilhas de cadastro (reimportável): o código, salvo EQUIPAMENTO, que sai como "EPI". */
    private const PLANILHA = ['EQUIPAMENTO' => 'EPI'];

    public static function rotulo(?string $codigo): ?string
    {
        return $codigo === null ? null : (self::ROTULOS[$codigo] ?? $codigo);
    }

    public static function planilha(?string $codigo): ?string
    {
        return $codigo === null ? null : (self::PLANILHA[$codigo] ?? $codigo);
    }

    /** Aceita o código ou o rótulo (ex.: "EPI", "Munição") e devolve o código do banco; desconhecido volta em maiúsculas para a validação recusar. */
    public static function normalizar(?string $v): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $v = mb_strtoupper(trim($v));
        foreach (self::ROTULOS as $codigo => $rotulo) {
            if ($v === $codigo || $v === mb_strtoupper($rotulo) || $v === self::semAcento(mb_strtoupper($rotulo))) {
                return $codigo;
            }
        }

        return $v;
    }

    private static function semAcento(string $s): string
    {
        return strtr($s, ['Ç' => 'C', 'Ã' => 'A', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    }
}
