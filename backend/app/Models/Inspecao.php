<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** RF-018 — registro de inspeção (imutável por trigger). */
class Inspecao extends Model
{
    const UPDATED_AT = null;

    protected $table = 'inspecoes';

    protected $fillable = ['acautelamento_id', 'inspetor_id', 'realizada_em', 'resultado', 'observacao'];

    protected $casts = ['realizada_em' => 'datetime'];

    public function inspetor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'inspetor_id');
    }
}
