<?php

namespace App\Models;

use App\Models\Concerns\Imutavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoVinculo extends Model
{
    use Imutavel;

    const UPDATED_AT = null;
    protected $table = 'documento_vinculos';
    protected $fillable = ['documento_id', 'movimentacao_id', 'vinculado_por_id'];

    public function movimentacao(): BelongsTo { return $this->belongsTo(Movimentacao::class); }
}
