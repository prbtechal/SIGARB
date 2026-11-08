<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item de catálogo. `modo_controle` INDIVIDUAL|LOTE (P-04). */
class Material extends Model
{
    use HasFactory;

    public const INDIVIDUAL = 'INDIVIDUAL';

    public const LOTE = 'LOTE';

    protected $table = 'materiais';

    protected $fillable = ['categoria_id', 'tipo_id', 'fabricante_id', 'calibre_id', 'codigo', 'nome', 'modelo', 'marca', 'compatibilidade', 'cor', 'tamanho', 'modo_controle', 'unidade_medida', 'ativo', 'ficha_tecnica'];

    protected $casts = ['ativo' => 'boolean', 'ficha_tecnica' => 'array'];

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoMaterial::class, 'tipo_id');
    }

    public function fabricante(): BelongsTo
    {
        return $this->belongsTo(Fabricante::class);
    }

    public function calibre(): BelongsTo
    {
        return $this->belongsTo(Calibre::class);
    }

    public function possuiEstoque(): bool
    {
        return ItemIndividual::where('material_id', $this->id)->exists()
            || Lote::where('material_id', $this->id)->exists();
    }
}
