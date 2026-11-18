<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Grupos objetivos do painel "Início" (decisão do responsável, 2026-11-18): armas de fogo, munição, coletes, tasers, cartuchos de Taser,
 * rádios, algemas e outros. Derivados do catálogo oficial (CatalogoOficial) por CÓDIGO da categoria + PERFIL do tipo — nunca pelo nome
 * do material. Só Colete e Rádio usam o nome do tipo, que é o oficial (constante do catálogo, não texto livre do cadastro).
 * A ordem dos WHEN importa: o primeiro que casar vence (ex.: cartucho de Taser fica em 011 Munição, mas tem grupo próprio).
 */
final class GrupoMaterial
{
    public const ROTULOS = [
        'ARMA_FOGO' => 'Armas de fogo', 'MUNICAO' => 'Munição', 'COLETE' => 'Coletes', 'TASER' => 'Tasers',
        'CARTUCHO_TASER' => 'Cartuchos de Taser', 'RADIO' => 'Rádios', 'ALGEMA' => 'Algemas', 'OUTROS' => 'Outros materiais',
    ];

    /** Tipo oficial (catálogo) de cada grupo que precisa do tipo além da categoria. */
    private const TIPO_COLETE = ['001', 'Colete balístico'];

    private const TIPO_RADIO = ['002', 'Rádio'];

    public static function chaves(): array
    {
        return array_keys(self::ROTULOS);
    }

    /**
     * Expressão SQL do grupo. `$c` = alias de `categorias` (codigo, natureza), `$t` = alias de `tipos_material` (perfil, nome; LEFT JOIN).
     * Valores vêm de constantes (sem entrada do usuário); os textos passam por quote() do PDO.
     */
    public static function sql(string $c = 'c', string $t = 't'): string
    {
        $q = fn (string $v) => DB::getPdo()->quote($v);
        [$catColete, $tipoColete] = self::TIPO_COLETE;
        [$catRadio, $tipoRadio] = self::TIPO_RADIO;

        return "CASE
            WHEN $t.perfil = 'ARMA_FOGO' THEN 'ARMA_FOGO'
            WHEN $c.codigo = '009' AND $t.perfil = 'BAIXA_LETALIDADE' THEN 'TASER'
            WHEN $c.codigo = '011' AND $t.perfil = 'ACESSORIO' THEN 'CARTUCHO_TASER'
            WHEN $c.codigo = '011' OR $c.natureza = 'MUNICAO' THEN 'MUNICAO'
            WHEN $t.perfil = 'COLETE' OR ($c.codigo = {$q($catColete)} AND $t.nome = {$q($tipoColete)}) THEN 'COLETE'
            WHEN $c.codigo = {$q($catRadio)} AND $t.nome = {$q($tipoRadio)} THEN 'RADIO'
            WHEN $c.codigo = '003' THEN 'ALGEMA'
            ELSE 'OUTROS' END";
    }

    /** Filtra uma consulta pelo grupo (mesma expressão do painel: o número do card bate com a lista aberta pelo link). */
    public static function filtrar(Builder $q, string $grupo, string $c = 'c', string $t = 't'): void
    {
        $q->whereRaw('('.self::sql($c, $t).') = ?', [$grupo]);
    }
}
