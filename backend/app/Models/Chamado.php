<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Central de Suporte. Rotas usam `codigo` (ULID); `id` nunca sai da API. */
class Chamado extends Model
{
    public const STATUS = ['ABERTO', 'EM_ATENDIMENTO', 'AGUARDANDO_SOLICITANTE', 'RESOLVIDO', 'CANCELADO'];

    public const PRIORIDADES = ['BAIXA', 'MEDIA', 'ALTA', 'CRITICA'];

    public const CATEGORIAS = ['ERRO', 'DUVIDA', 'ACESSO', 'DADOS', 'SUGESTAO'];

    /** Transições permitidas ao atendimento (reabertura é ação própria). */
    public const TRANSICOES = [
        'ABERTO' => ['EM_ATENDIMENTO', 'AGUARDANDO_SOLICITANTE', 'RESOLVIDO', 'CANCELADO'],
        'EM_ATENDIMENTO' => ['AGUARDANDO_SOLICITANTE', 'RESOLVIDO', 'CANCELADO'],
        'AGUARDANDO_SOLICITANTE' => ['EM_ATENDIMENTO', 'RESOLVIDO', 'CANCELADO'],
        'RESOLVIDO' => [],
        'CANCELADO' => [],
    ];

    protected $fillable = ['codigo', 'protocolo', 'solicitante_id', 'unidade_id', 'categoria', 'prioridade', 'status', 'assunto',
        'atribuido_a_id', 'diagnostico', 'versao', 'reaberturas', 'resolvido_em'];

    protected function casts(): array
    {
        return ['diagnostico' => 'array', 'resolvido_em' => 'datetime', 'versao' => 'integer', 'reaberturas' => 'integer'];
    }

    public function getRouteKeyName(): string
    {
        return 'codigo';
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitante_id');
    }

    public function atribuidoA(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'atribuido_a_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(ChamadoMensagem::class)->orderBy('id');
    }
}
