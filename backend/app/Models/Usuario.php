<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Identidade de autenticação (SRS §2.1). Não confundir com Militar. */
class Usuario extends Authenticatable
{
    use HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['nome', 'login', 'email', 'password', 'ativo', 'militar_id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'ativo' => 'boolean', 'desativado_em' => 'datetime', 'ultimo_login_em' => 'datetime'];
    }

    public function atribuicoes(): HasMany
    {
        return $this->hasMany(AtribuicaoPapel::class);
    }

    public function militar(): BelongsTo
    {
        return $this->belongsTo(Militar::class);
    }
}
