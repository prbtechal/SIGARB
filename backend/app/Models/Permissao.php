<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permissao extends Model
{
    protected $table = 'permissoes';
    public $timestamps = false;
    protected $fillable = ['codigo', 'descricao'];
}
