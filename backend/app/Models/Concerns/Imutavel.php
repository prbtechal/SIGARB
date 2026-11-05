<?php

namespace App\Models\Concerns;

use LogicException;

/** Defesa em profundidade para registros append-only (os triggers MySQL são a garantia final). */
trait Imutavel
{
    protected static function bootImutavel(): void
    {
        static::updating(fn () => throw new LogicException('Registro imutável: '.static::class));
        static::deleting(fn () => throw new LogicException('Registro imutável: '.static::class));
    }
}
