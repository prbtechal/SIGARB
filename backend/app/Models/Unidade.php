<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Unidade/subunidade. `caminho` é mantido automaticamente (ADR-004). */
class Unidade extends Model
{
    use HasFactory;

    protected $table = 'unidades';
    protected $fillable = ['organizacao_id', 'unidade_superior_id', 'sigla', 'nome', 'tipo', 'ativo'];
    protected $casts = ['ativo' => 'boolean'];

    protected static function booted(): void
    {
        static::created(function (Unidade $u) {
            $prefixo = $u->unidade_superior_id ? Unidade::findOrFail($u->unidade_superior_id)->caminho : '/';
            $u->caminho = $prefixo.$u->id.'/';
            $u->saveQuietly();
        });
    }

    public function organizacao(): BelongsTo { return $this->belongsTo(Organizacao::class); }
    public function superior(): BelongsTo { return $this->belongsTo(Unidade::class, 'unidade_superior_id'); }
    public function locais(): HasMany { return $this->hasMany(LocalEstoque::class); }

    public function contem(Unidade $outra): bool
    {
        return str_starts_with($outra->caminho, $this->caminho);
    }
}
