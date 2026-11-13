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

    protected $fillable = ['funcionario_id', 'cpf', 'unidade_id', 'posto_graduacao_id', 'nome', 'nome_guerra', 'matricula', 'situacao', 'categoria_efetivo', 'quadro', 'funcao', 'telefone_institucional', 'email_institucional', 'observacoes_administrativas', 'foto_caminho', 'foto_atualizada_em'];

    protected $hidden = ['foto_caminho'];

    protected $casts = ['foto_atualizada_em' => 'datetime'];

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function postoGraduacao(): BelongsTo
    {
        return $this->belongsTo(PostoGraduacao::class);
    }

    public function usuario(): HasOne
    {
        return $this->hasOne(Usuario::class);
    }

    public const CATEGORIAS = ['RR' => 'RR (PM/BM)', 'R1' => 'R1', 'VIGILANTE' => 'Vigilante', 'PM_ATIVA' => 'PM ativo', 'BM_ATIVA' => 'BM ativo'];

    /** Só RR (PM/BM) e PM/BM da ativa podem pedir e receber material letal (armamento e munição). */
    public const APTAS_LETAL = ['RR', 'PM_ATIVA', 'BM_ATIVA'];

    public const NATUREZAS_LETAIS = ['ARMAMENTO', 'MUNICAO'];

    public function exigirAptoLetal(): void
    {
        if (! in_array($this->categoria_efetivo, self::APTAS_LETAL, true)) {
            $tipo = self::CATEGORIAS[$this->categoria_efetivo] ?? 'não informado';
            throw new \App\Support\SigarbException('EFETIVO_NAO_APTO_LETAL', "Armamento e munição só podem ser entregues a RR (PM/BM) ou PM/BM da ativa. Tipo de efetivo de {$this->nome_guerra}: {$tipo}.", 422);
        }
    }
}
