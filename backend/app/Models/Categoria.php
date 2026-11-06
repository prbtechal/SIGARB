<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Categoria extends Model
{
    protected $table = 'categorias';

    public $timestamps = false;

    protected $casts = ['ativo' => 'boolean', 'desativado_em' => 'datetime'];

    protected $fillable = ['codigo', 'nome', 'natureza', 'ativo', 'desativado_em'];
}
