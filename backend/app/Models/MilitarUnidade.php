<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CP-12 — vínculo secundário temporal militar↔unidade. Não altera escopo de acesso (ADR-004). */
class MilitarUnidade extends Model
{
    protected $table = 'militar_unidades';

    protected $fillable = ['militar_id', 'unidade_id', 'inicio', 'fim', 'registrado_por_id', 'encerrado_por_id'];

    protected $casts = ['inicio' => 'date:Y-m-d', 'fim' => 'date:Y-m-d'];

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }
}
