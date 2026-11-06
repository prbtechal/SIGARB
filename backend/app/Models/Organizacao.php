<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organizacao extends Model
{
    use HasFactory;

    protected $table = 'organizacoes';

    protected $fillable = ['sigla', 'nome', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidade::class);
    }
}
