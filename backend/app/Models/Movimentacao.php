<?php

namespace App\Models;

use App\Models\Concerns\Imutavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Razão de movimentações (RN-001, RN-017). Append-only. */
class Movimentacao extends Model
{
    use Imutavel;

    const UPDATED_AT = null;

    protected $table = 'movimentacoes';

    protected $fillable = ['tipo', 'local_origem_id', 'local_destino_id', 'responsavel_id', 'ocorrido_em', 'origem_descricao',
        'documento_referencia', 'justificativa', 'chave_idempotencia', 'hash_requisicao', 'estorno_de_id', 'vinculo_id', 'vinculo_parcial_id', 'provisoria', 'militar_id', 'previsao_retorno'];

    protected $casts = ['ocorrido_em' => 'datetime', 'provisoria' => 'boolean', 'previsao_retorno' => 'date'];

    protected $hidden = ['hash_requisicao'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function itens(): HasMany
    {
        return $this->hasMany(MovimentacaoItem::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsavel_id');
    }

    public function localOrigem(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class, 'local_origem_id');
    }

    public function localDestino(): BelongsTo
    {
        return $this->belongsTo(LocalEstoque::class, 'local_destino_id');
    }

    public function estornoDe(): BelongsTo
    {
        return $this->belongsTo(Movimentacao::class, 'estorno_de_id');
    }

    public function estorno(): HasOne
    {
        return $this->hasOne(Movimentacao::class, 'estorno_de_id');
    }

    public function vinculo(): BelongsTo
    {
        return $this->belongsTo(Movimentacao::class, 'vinculo_id');
    }

    /** CP-13: devoluções parciais de uma entrega (a final fica em conclusao). */
    public function devolucoesParciais(): HasMany
    {
        return $this->hasMany(Movimentacao::class, 'vinculo_parcial_id');
    }

    public function conclusao(): HasOne
    {
        return $this->hasOne(Movimentacao::class, 'vinculo_id');
    }

    public function militar(): BelongsTo
    {
        return $this->belongsTo(Militar::class);
    }
}
