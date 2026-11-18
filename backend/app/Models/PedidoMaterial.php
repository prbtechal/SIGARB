<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido prévio de material feito pelo próprio efetivo (portal do efetivo, decisão do responsável 2026-11-18).
 * PENDENTE → (APROVADO | RECUSADO | CANCELADO); APROVADO → (ENTREGUE | CANCELADO). Estado muda só pelo PedidoMaterialService.
 */
class PedidoMaterial extends Model
{
    public const STATUS = ['PENDENTE', 'APROVADO', 'RECUSADO', 'CANCELADO', 'ENTREGUE'];

    /** Pedidos que contam no limite de pedidos abertos do efetivo. */
    public const ABERTOS = ['PENDENTE', 'APROVADO'];

    protected $table = 'pedidos_material';

    protected $fillable = ['codigo', 'militar_id', 'unidade_id', 'solicitante_id', 'status', 'retirada_prevista', 'local_retirada_id', 'observacao',
        'decidido_por_id', 'decidido_em', 'motivo_recusa', 'observacao_armeiro', 'cancelado_em', 'entregue_por_id', 'entregue_em',
        'entrega_movimentacao_id', 'entrega_chave', 'versao'];

    protected $hidden = ['entrega_chave'];

    protected $casts = ['retirada_prevista' => 'date', 'decidido_em' => 'datetime', 'cancelado_em' => 'datetime', 'entregue_em' => 'datetime'];

    public function itens(): HasMany
    {
        return $this->hasMany(PedidoMaterialItem::class, 'pedido_id')->orderBy('id');
    }

    public function militar(): BelongsTo
    {
        return $this->belongsTo(Militar::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'decidido_por_id');
    }

    public function entregador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'entregue_por_id');
    }

    public function localRetirada(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class, 'local_retirada_id');
    }

    public function acautelamentos(): BelongsToMany
    {
        return $this->belongsToMany(Acautelamento::class, 'pedido_material_acautelamentos', 'pedido_id', 'acautelamento_id');
    }
}
