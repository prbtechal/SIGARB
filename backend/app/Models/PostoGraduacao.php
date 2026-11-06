<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostoGraduacao extends Model
{
    protected $table = 'postos_graduacoes';

    public $timestamps = false;

    protected $fillable = ['sigla', 'nome', 'ordem'];
}
