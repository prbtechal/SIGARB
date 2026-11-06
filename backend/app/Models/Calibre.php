<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Calibre extends Model
{
    protected $table = 'calibres';

    public $timestamps = false;

    protected $casts = ['ativo' => 'boolean', 'desativado_em' => 'datetime'];

    protected $fillable = ['nome', 'ativo', 'desativado_em'];
}
