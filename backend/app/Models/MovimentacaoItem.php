<?php

namespace App\Models;

use App\Models\Concerns\Imutavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentacaoItem extends Model
{
    use Imutavel;

    public $timestamps = false;

    protected $table = 'movimentacao_itens';

    protected $fillable = ['movimentacao_id', 'item_individual_id', 'lote_id', 'quantidade'];

    protected $casts = ['quantidade' => 'integer'];

    public function itemIndividual(): BelongsTo
    {
        return $this->belongsTo(ItemIndividual::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }
}
