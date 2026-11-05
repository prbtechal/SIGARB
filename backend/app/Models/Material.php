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
    protected $fillable = ['categoria_id', 'fabricante_id', 'calibre_id', 'codigo', 'nome', 'modelo', 'modo_controle', 'unidade_medida', 'ativo'];
    protected $casts = ['ativo' => 'boolean'];

    public function categoria(): BelongsTo { return $this->belongsTo(Categoria::class); }
    public function fabricante(): BelongsTo { return $this->belongsTo(Fabricante::class); }
    public function calibre(): BelongsTo { return $this->belongsTo(Calibre::class); }

    public function possuiEstoque(): bool
    {
        return ItemIndividual::where('material_id', $this->id)->exists()
            || Lote::where('material_id', $this->id)->exists();
    }
}
