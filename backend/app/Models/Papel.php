<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Papel extends Model
{
    protected $table = 'papeis';

    public $timestamps = false;

    protected $fillable = ['codigo', 'nome', 'descricao'];

    public function permissoes(): BelongsToMany
    {
        return $this->belongsToMany(Permissao::class, 'papel_permissao');
    }
}
