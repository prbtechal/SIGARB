<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** RF-014. `armazenamento_id` nunca sai da API; rotas usam `codigo_publico` (ULID). */
class Documento extends Model
{
    protected $table = 'documentos';
    protected $fillable = ['codigo_publico', 'tipo_documento_id', 'unidade_id', 'titulo', 'descricao', 'numero_referencia', 'nome_original',
        'armazenamento_id', 'mime', 'extensao', 'tamanho_bytes', 'sha256', 'situacao', 'enviado_por_id', 'arquivado_em', 'arquivado_por_id'];
    protected $hidden = ['armazenamento_id'];
    protected $casts = ['tamanho_bytes' => 'integer', 'arquivado_em' => 'datetime'];

    public function getRouteKeyName(): string { return 'codigo_publico'; }
    public function tipo(): BelongsTo { return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id'); }
    public function unidade(): BelongsTo { return $this->belongsTo(Unidade::class); }
    public function enviadoPor(): BelongsTo { return $this->belongsTo(Usuario::class, 'enviado_por_id'); }
    public function vinculos(): HasMany { return $this->hasMany(DocumentoVinculo::class); }

    /** Caminho relativo no disco privado; derivado apenas do identificador aleatório. */
    public function caminho(): string
    {
        return 'documentos/'.substr($this->armazenamento_id, 0, 2).'/'.substr($this->armazenamento_id, 2, 2).'/'.$this->armazenamento_id;
    }
}
