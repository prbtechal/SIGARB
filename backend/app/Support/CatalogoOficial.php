<?php

namespace App\Support;

/**
 * Catálogo oficial do gestor — documento "Categorias e materiais" + decisões do responsável (2026-11-11).
 * Ver docs/requisitos/CATALOGO-OFICIAL.md. Usado pela migração 2026_11_20_000100 e pela carga do inventário.
 *
 * "Letal"/"Não letal" (subdivisão de Armas) é rótulo no nome do tipo; o modelo é só categoria → tipo.
 * Controle individual/lote é do material (P-04); o perfil só o restringe quando o documento fixa um único modo.
 */
final class CatalogoOficial
{
    /** código => [nome, natureza, prefixo do identificador do item]. 004 e 005 já existem em produção (cadastro do gestor) e não são alteradas. */
    public const CATEGORIAS = [
        '001' => ['EPI', 'EQUIPAMENTO', 'EPI'],
        '002' => ['Comunicação', 'EQUIPAMENTO', 'COM'],
        '003' => ['Contenção', 'EQUIPAMENTO', 'CON'],
        '004' => ['Treinamento', 'TREINAMENTO', 'TRE'],
        '005' => ['Sinalização', 'EQUIPAMENTO', 'SIN'],
        '006' => ['Observação', 'EQUIPAMENTO', 'OBS'],
        '007' => ['Acessórios operacionais', 'EQUIPAMENTO', 'ACO'],
        '008' => ['Produtos', 'PRODUTO', 'PRO'],
        '009' => ['Armas', 'ARMAMENTO', 'ARM'],
        '010' => ['Equipamento tático', 'EQUIPAMENTO', 'TAT'],
        '011' => ['Munição', 'MUNICAO', 'MUN'], // decisão do responsável (2026-11-16): munição sai de 009 Armas
    ];

    /**
     * [código da categoria, nome do tipo, perfil]. Toda categoria tem também o tipo "Geral" (GERAL), como na tela.
     * Munição letal e Colete balístico ficam GERAL: os perfis MUNICAO/COLETE exigem fabricante e nível de proteção/fabricação,
     * que os materiais da carga de 2026 não têm (o perfil de tipo em uso não muda depois — ReferenciaService).
     */
    public const TIPOS = [
        ['009', 'Arma de fogo (letal)', 'ARMA_FOGO'],
        ['011', 'Munição letal', 'GERAL'],
        ['009', 'Taser ou equipamento semelhante (não letal)', 'BAIXA_LETALIDADE'],
        ['009', 'Acessório de Taser (não letal)', 'ACESSORIO'],
        ['011', 'Munição de baixa letalidade', 'MUNICAO'],
        ['011', 'Cartucho de Taser (não letal)', 'ACESSORIO'], // decisão do responsável (2026-11-16): cartucho é munição
        ['001', 'Colete balístico', 'GERAL'],
        ['002', 'Rádio', 'GERAL'],
        ['003', 'Algemas e acessórios', 'GERAL'],
        ['010', 'Coldre', 'ACESSORIO'],
        ['010', 'Cinto tático', 'ACESSORIO'],
        ['010', 'Porta-algema', 'ACESSORIO'],
        ['010', 'Porta-carregador', 'ACESSORIO'],
        ['010', 'Fiel retrátil', 'ACESSORIO'],
        ['010', 'Porta-objetos', 'ACESSORIO'],
        ['010', 'Bateria', 'ACESSORIO'], // decisão do responsável (2026-11-16): baterias são equipamento, não arma
    ];

    /** Tipos de munição que a migração 2026_11_22_000100 leva de 009 Armas para 011 Munição. */
    public const TIPOS_MUNICAO = ['Munição letal', 'Munição de baixa letalidade'];

    /** Categoria da carga de 2026 (código antigo / chave interna) => [código oficial, tipo oficial, tipo antigo na carga]. */
    public const CARGA = [
        'ARMAMENTO' => ['009', 'Arma de fogo (letal)', 'Pistola'],
        'TASER' => ['009', 'Taser ou equipamento semelhante (não letal)', 'Dispositivo de energia'],
        'ACESSORIO_TASER' => ['009', 'Acessório de Taser (não letal)', 'Acessório de Taser'],
        'MUNICAO' => ['011', 'Munição letal', 'Geral'],
        'BATERIA_TASER' => ['010', 'Bateria', 'Acessório de Taser'],
        'CARTUCHO_TASER' => ['011', 'Cartucho de Taser (não letal)', 'Acessório de Taser'],
        'COLETE' => ['001', 'Colete balístico', 'Geral'],
        'ALGEMA' => ['003', 'Algemas e acessórios', 'Geral'],
        'COMUNICACAO' => ['002', 'Rádio', 'Geral'],
    ];

    /** Material de bateria (nome começa por "Bateria"/"Baterias", sem acento/caixa). */
    public static function ehBateria(string $nome): bool
    {
        return (bool) preg_match('/^\s*baterias?\b/iu', $nome);
    }

    /** Material de cartucho (nome começa por "Cartucho"/"Cartuchos"). */
    public static function ehCartucho(string $nome): bool
    {
        return (bool) preg_match('/^\s*cartuchos?\b/iu', $nome);
    }

    public static function perfil(string $categoria, string $tipo): string
    {
        foreach (self::TIPOS as [$c, $n, $p]) {
            if ($c === $categoria && $n === $tipo) {
                return $p;
            }
        }

        return 'GERAL';
    }
}
