<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only (triggers). INTERNA nunca é exposta ao solicitante. */
class ChamadoMensagem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'chamado_mensagens';

    protected $fillable = ['chamado_id', 'autor_id', 'tipo', 'corpo', 'redigido'];

    protected function casts(): array
    {
        return ['redigido' => 'boolean', 'created_at' => 'datetime'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autor_id');
    }
}
