<?php

namespace App\Support;

use RuntimeException;

/** Erro de domínio com código estável para a API (formato em docs/api/openapi.yaml). */
class SigarbException extends RuntimeException
{
    public function __construct(
        public readonly string $codigo,
        string $mensagem,
        public readonly int $status = 409,
        public readonly array $detalhes = [],
    ) {
        parent::__construct($mensagem);
    }

    public static function operacaoBloqueada(string $operacao): self
    {
        $cfg = config("sigarb.operacoes.$operacao");

        return new self('OPERACAO_BLOQUEADA',
            "Operação '{$cfg['rotulo']}' indisponível: depende da decisão institucional {$cfg['pendencia']}.",
            409, ['operacao' => $operacao, 'pendencia' => $cfg['pendencia']]);
    }
}
