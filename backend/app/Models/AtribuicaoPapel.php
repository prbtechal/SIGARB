<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtribuicaoPapel extends Model
{
    protected $table = 'atribuicoes_papel';
    protected $fillable = ['usuario_id', 'papel_id', 'unidade_id', 'concedido_por_id'];

    public function usuario(): BelongsTo { return $this->belongsTo(Usuario::class); }
    public function papel(): BelongsTo { return $this->belongsTo(Papel::class); }
    public function unidade(): BelongsTo { return $this->belongsTo(Unidade::class); }
}
