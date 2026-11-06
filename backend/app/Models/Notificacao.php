<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Notificação in-app. Texto curto e sem conteúdo do chamado (só protocolo e evento). */
class Notificacao extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'notificacoes';

    protected $fillable = ['usuario_id', 'tipo', 'chamado_id', 'texto', 'lida_em'];

    protected function casts(): array
    {
        return ['lida_em' => 'datetime', 'created_at' => 'datetime'];
    }

    public function chamado(): BelongsTo
    {
        return $this->belongsTo(Chamado::class);
    }
}
