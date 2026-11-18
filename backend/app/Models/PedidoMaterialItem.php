<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha do pedido: material do catálogo, quantidade pedida, aprovada (o armeiro pode reduzir) e entregue. */
class PedidoMaterialItem extends Model
{
    protected $table = 'pedido_material_itens';

    protected $fillable = ['pedido_id', 'material_id', 'quantidade', 'quantidade_aprovada', 'quantidade_entregue'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
