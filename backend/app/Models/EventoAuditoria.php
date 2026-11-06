<?php

namespace App\Models;

use App\Models\Concerns\Imutavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evento de auditoria (RF-025, ADR-007). Append-only, encadeado por hash. */
class EventoAuditoria extends Model
{
    use Imutavel;

    public $timestamps = false;

    protected $table = 'eventos_auditoria';

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u'; // preserva microssegundos (entram no hash)

    protected $casts = ['dados' => 'array', 'ocorrido_em' => 'datetime'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
