<?php

namespace App\Models;

use App\Services\AutorizacaoService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

/** Identidade de autenticação (SRS §2.1). Não confundir com Militar. */
class Usuario extends Authenticatable
{
    use HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['nome', 'login', 'email', 'password', 'ativo', 'militar_id', 'posto_graduacao_id', 'cargo', 'matricula', 'telefone'];

    /** Dados funcionais da conta. A própria pessoa edita posto, cargo e telefone (PUT /me/profile); matrícula só quem gere contas. */
    public const CAMPOS_FUNCIONAIS = ['posto_graduacao_id', 'cargo', 'matricula', 'telefone'];

    protected $hidden = ['password', 'remember_token', 'mfa_segredo', 'mfa_recuperacao'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'ativo' => 'boolean', 'desativado_em' => 'datetime', 'ultimo_login_em' => 'datetime', 'senha_provisoria' => 'boolean',
            'senha_alterada_em' => 'datetime', 'bloqueado_em' => 'datetime', 'mfa_segredo' => 'encrypted', 'mfa_confirmado_em' => 'datetime', 'mfa_recuperacao' => 'array'];
    }

    /** PSI-PRB cap. 10: toda gravação de senha (criação, troca, redefinição) marca a data e entra no histórico. */
    protected static function booted(): void
    {
        static::saving(function (Usuario $u) {
            if ($u->isDirty('password')) {
                $u->senha_alterada_em = now();
            }
        });
        $historico = fn (Usuario $u) => DB::table('historico_senhas')->insert(['usuario_id' => $u->id, 'password_hash' => $u->password, 'criado_em' => now()]);
        static::created($historico);
        static::updated(fn (Usuario $u) => $u->wasChanged('password') && $historico($u));
    }

    public function senhaExpirada(): bool
    {
        $dias = (int) config('sigarb.senha.validade_dias');

        return $dias > 0 && $this->senha_alterada_em !== null && $this->senha_alterada_em->lt(now()->subDays($dias));
    }

    /** Permissões que tornam a conta privilegiada (gestão de contas, sessões, permissões e ajustes do sistema). */
    public const PERMISSOES_PRIVILEGIADAS = ['usuarios.gerir', 'sessoes.revogar', 'sistema.configurar'];

    /** Pelas permissões efetivas (em qualquer escopo), não pelo código do papel: a matriz é editável pela tela. */
    public function privilegiada(): bool
    {
        $authz = app(AutorizacaoService::class);
        $authz->limpar();

        return array_intersect(self::PERMISSOES_PRIVILEGIADAS, $authz->permissoes($this)) !== [];
    }

    public function mfaAtivo(): bool
    {
        return $this->mfa_confirmado_em !== null;
    }

    /** Papel (vigente) listado em sigarb.mfa.papeis_obrigatorios. */
    public function exigeMfa(): bool
    {
        $papeis = config('sigarb.mfa.papeis_obrigatorios');

        return $papeis !== [] && $this->atribuicoes()->whereHas('papel', fn ($q) => $q->whereIn('codigo', $papeis))
            ->where(fn ($q) => $q->whereNull('expira_em')->orWhere('expira_em', '>', now()))->exists();
    }

    public function atribuicoes(): HasMany
    {
        return $this->hasMany(AtribuicaoPapel::class);
    }

    public function postoGraduacao(): BelongsTo
    {
        return $this->belongsTo(PostoGraduacao::class);
    }

    public function militar(): BelongsTo
    {
        return $this->belongsTo(Militar::class);
    }
}
