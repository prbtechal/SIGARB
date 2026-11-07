<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-020 — inventário (regra provisória). Estado muda só pelo InventarioService. */
class Inventario extends Model
{
    protected $table = 'inventarios';

    protected $fillable = ['codigo', 'local_estoque_id', 'unidade_id', 'estado', 'aberto_por', 'contado_por', 'decidido_por', 'decidido_em', 'observacao', 'ajuste_falta_id', 'ajuste_sobra_id', 'versao'];

    protected $casts = ['decidido_em' => 'datetime'];

    protected $hidden = ['local_em_curso'];

    public function itens(): HasMany
    {
        return $this->hasMany(InventarioItem::class);
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class, 'local_estoque_id');
    }

    public function abertoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aberto_por');
    }

    public function contadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'contado_por');
    }

    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'decidido_por');
    }
}
