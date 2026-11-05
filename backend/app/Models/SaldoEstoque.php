<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saldo atual (projeção). Só o EstoqueService o altera, dentro de transação com lock. */
class SaldoEstoque extends Model
{
    protected $table = 'saldos_estoque';
    protected $fillable = ['local_estoque_id', 'lote_id', 'quantidade', 'quantidade_reservada'];
    protected $casts = ['quantidade' => 'integer', 'quantidade_reservada' => 'integer'];

    public function lote(): BelongsTo { return $this->belongsTo(Lote::class); }
    public function local(): BelongsTo { return $this->belongsTo(LocalEstoque::class, 'local_estoque_id'); }
}
