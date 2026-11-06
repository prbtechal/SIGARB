<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fabricante extends Model
{
    protected $table = 'fabricantes';

    public $timestamps = false;

    protected $casts = ['ativo' => 'boolean', 'desativado_em' => 'datetime'];

    protected $fillable = ['nome', 'ativo', 'desativado_em'];
}
