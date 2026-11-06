<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventarioItem extends Model
{
    public $timestamps = false;

    protected $table = 'inventario_itens';

    protected $fillable = ['inventario_id', 'lote_id', 'item_individual_id', 'esperado', 'contado', 'contado_por'];

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function itemIndividual(): BelongsTo
    {
        return $this->belongsTo(ItemIndividual::class);
    }
}
