<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-015..018 — acautelamento com regra provisória. Estado muda só pelo AcautelamentoService. */
class Acautelamento extends Model
{
    protected $table = 'acautelamentos';

    protected $fillable = ['codigo', 'militar_id', 'item_individual_id', 'unidade_id', 'estado', 'solicitante_id', 'justificativa',
        'aprovador_id', 'decidido_em', 'decisao_justificativa', 'entrega_id', 'devolucao_id', 'proxima_inspecao', 'carteira_token', 'versao'];

    protected $casts = ['decidido_em' => 'datetime', 'proxima_inspecao' => 'date'];

    protected $hidden = ['carteira_token', 'item_em_curso'];

    public function militar(): BelongsTo
    {
        return $this->belongsTo(Militar::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemIndividual::class, 'item_individual_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    public function aprovador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'aprovador_id');
    }

    public function inspecoes(): HasMany
    {
        return $this->hasMany(Inspecao::class)->orderByDesc('realizada_em');
    }
}
