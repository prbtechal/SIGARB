<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** CP-13 — lista de referência de níveis de proteção (lista oficial pendente: P-04). */
class NivelProtecao extends Model
{
    protected $table = 'niveis_protecao';

    protected $casts = ['ativo' => 'boolean', 'desativado_em' => 'datetime'];

    protected $fillable = ['nome', 'descricao', 'ativo', 'desativado_em'];

    /** @return list<string> */
    public static function ativos(): array
    {
        return self::where('ativo', true)->orderBy('nome')->pluck('nome')->all();
    }
}
