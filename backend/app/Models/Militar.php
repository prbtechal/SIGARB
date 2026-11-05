<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Registro institucional de pessoa (SRS §2.1). Dados mínimos (ADR-009, P-09). */
class Militar extends Model
{
    use HasFactory;

    protected $table = 'militares';
    protected $fillable = ['unidade_id', 'posto_graduacao_id', 'nome', 'nome_guerra', 'matricula', 'situacao'];

    public function unidade(): BelongsTo { return $this->belongsTo(Unidade::class); }
    public function postoGraduacao(): BelongsTo { return $this->belongsTo(PostoGraduacao::class); }
    public function usuario(): HasOne { return $this->hasOne(Usuario::class); }
}
