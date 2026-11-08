<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CP-12 — tipo de material abaixo da categoria; `perfil` define os campos predefinidos (App\Support\PerfilMaterial). */
class TipoMaterial extends Model
{
    protected $table = 'tipos_material';

    protected $fillable = ['categoria_id', 'nome', 'descricao', 'perfil', 'ativo', 'desativado_em'];

    protected $casts = ['ativo' => 'boolean', 'desativado_em' => 'datetime'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }
}
