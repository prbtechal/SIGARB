<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lote extends Model
{
    protected $table = 'lotes';
    protected $fillable = ['material_id', 'codigo', 'fabricante_id', 'validade'];
    protected $casts = ['validade' => 'date:Y-m-d'];

    public function material(): BelongsTo { return $this->belongsTo(Material::class); }
}
