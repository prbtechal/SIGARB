<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalEstoque extends Model
{
    use HasFactory;

    protected $table = 'locais_estoque';

    protected $fillable = ['unidade_id', 'codigo', 'nome', 'tipo', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }
}
