<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Notificação in-app. Texto curto e sem conteúdo do chamado (só protocolo e evento). */
class Notificacao extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'notificacoes';

    protected $fillable = ['usuario_id', 'tipo', 'chamado_id', 'pedido_material_id', 'texto', 'lida_em'];

    protected function casts(): array
    {
        return ['lida_em' => 'datetime', 'created_at' => 'datetime'];
    }

    public function chamado(): BelongsTo
    {
        return $this->belongsTo(Chamado::class);
    }

    /** Portal do efetivo: notificação ligada a um pedido de material (só código e evento no texto). */
    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoMaterial::class, 'pedido_material_id');
    }
}
