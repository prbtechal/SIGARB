<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemIndividual extends Model
{
    protected $table = 'itens_individuais';

    protected $fillable = ['material_id', 'identificador', 'numero_serie', 'local_estoque_id', 'situacao', 'estado_conservacao', 'fabricado_em', 'validade', 'tamanho', 'nivel_protecao'];

    protected $casts = ['fabricado_em' => 'date:Y-m-d', 'validade' => 'date:Y-m-d'];

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class, 'local_estoque_id');
    }
}
