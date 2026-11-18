<?php

namespace App\Support;

/**
 * Listas fechadas do formulário "Registrar entrega" (decisão do responsável, 2026-11-18; fonte: config/sigarb.php → entrega).
 * O que se grava continua nos campos de sempre da movimentação: documento_referencia e justificativa, com o rótulo escolhido.
 */
class EntregaOpcoes
{
    /** @return list<array{codigo: string, rotulo: string, descricao: string}> */
    public static function lista(string $qual): array
    {
        return collect(config("sigarb.entrega.$qual"))->map(fn ($v, $k) => ['codigo' => $k, 'rotulo' => $v[0], 'descricao' => $v[1]])->values()->all();
    }

    /** @return list<string> */
    public static function codigos(string $qual): array
    {
        return array_keys(config("sigarb.entrega.$qual"));
    }

    /** Documento: rótulo + número/identificação opcional; "Sem documento" (ou nada) = null. */
    public static function documento(?string $codigo, ?string $numero): ?string
    {
        if ($codigo === null || $codigo === 'SEM_DOCUMENTO') {
            return null;
        }
        $rotulo = config("sigarb.entrega.documentos.$codigo")[0];
        $numero = trim((string) $numero);

        return mb_substr($numero === '' ? $rotulo : "$rotulo nº $numero", 0, 120);
    }

    /** Motivo: "Outro" grava o texto descrito; os demais gravam o rótulo e, se houver, a observação. */
    public static function motivo(string $codigo, ?string $texto): string
    {
        $texto = trim((string) $texto);
        $rotulo = config("sigarb.entrega.motivos.$codigo")[0];
        $v = $codigo === 'OUTRO' ? "Outro — $texto" : ($texto === '' ? $rotulo : "$rotulo — $texto");

        return mb_substr($v, 0, 500);
    }
}
