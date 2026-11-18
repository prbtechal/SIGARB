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

    /** Posto "sem posto" que a sincronização com funcionários grava (a origem não traz posto). */
    public const POSTO_SEM = 'CIV';

    /** Decisão 2026-11-18: com posto CIV, a sigla exibida é a da categoria do efetivo. */
    public const SIGLAS_CATEGORIA = ['RR' => 'RR', 'PM_ATIVA' => 'PM', 'BM_ATIVA' => 'BM', 'VIGILANTE' => 'VIG', 'R1' => 'R1'];

    /** Categorias que têm posto/graduação real: com posto CIV, o cadastro está desatualizado (alerta para a T.I.). */
    public const CATEGORIAS_COM_POSTO = ['RR', 'PM_ATIVA', 'BM_ATIVA'];

    /**
     * Sigla exibida antes do nome (função única do sistema; espelho no front-end: components/efetivo.ts).
     * Posto real (SD, 3SGT…) = ele mesmo; CIV/vazio = sigla da categoria (RR, PM, BM, VIG, R1); sem categoria = nada.
     */
    public static function siglaExibicao(?string $posto, ?string $categoria): ?string
    {
        if ($posto !== null && $posto !== '' && $posto !== self::POSTO_SEM) {
            return $posto;
        }

        return self::SIGLAS_CATEGORIA[$categoria ?? ''] ?? null;
    }

    /** "RR CICERO LIMA", "SD SILVA" ou só "JOAO" (CIV sem categoria). */
    public static function rotulo(?string $posto, ?string $categoria, ?string $nomeGuerra): string
    {
        return trim(self::siglaExibicao($posto, $categoria).' '.$nomeGuerra);
    }

    /** Exige postoGraduacao carregado (ou carrega) e categoria_efetivo no select. */
    public function getSiglaExibicaoAttribute(): ?string
    {
        return self::siglaExibicao($this->postoGraduacao?->sigla, $this->categoria_efetivo);
    }

    public function getRotuloCurtoAttribute(): string
    {
        return trim($this->sigla_exibicao.' '.$this->nome_guerra);
    }

    /** RR/PM/BM com posto CIV: posto/graduação a atualizar. */
    public static function postoAAtualizar(?string $posto, ?string $categoria): bool
    {
        return in_array($categoria, self::CATEGORIAS_COM_POSTO, true) && ($posto === null || $posto === self::POSTO_SEM);
    }

    public function exigirAptoLetal(): void
    {
        if (! in_array($this->categoria_efetivo, self::APTAS_LETAL, true)) {
            $tipo = self::CATEGORIAS[$this->categoria_efetivo] ?? 'não informado';
            throw new \App\Support\SigarbException('EFETIVO_NAO_APTO_LETAL', "Armamento e munição só podem ser entregues a RR (PM/BM) ou PM/BM da ativa. Tipo de efetivo de {$this->nome_guerra}: {$tipo}.", 422);
        }
    }
}
