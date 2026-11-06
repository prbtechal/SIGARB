<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoDocumento extends Model
{
    protected $table = 'tipos_documento';
    public $timestamps = false;
    protected $casts = ['ativo' => 'boolean', 'desativado_em' => 'datetime'];
    protected $fillable = ['codigo', 'nome', 'ativo', 'desativado_em'];
}
