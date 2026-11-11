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
    /** código => [nome, natureza]. 004 e 005 já existem em produção (cadastro do gestor) e não são alteradas. */
    public const CATEGORIAS = [
        '001' => ['EPI', 'EQUIPAMENTO'],
        '002' => ['Comunicação', 'EQUIPAMENTO'],
        '003' => ['Contenção', 'EQUIPAMENTO'],
        '004' => ['Treinamento', 'TREINAMENTO'],
        '005' => ['Sinalização', 'EQUIPAMENTO'],
        '006' => ['Observação', 'EQUIPAMENTO'],
        '007' => ['Acessórios operacionais', 'EQUIPAMENTO'],
        '008' => ['Produtos', 'PRODUTO'],
        '009' => ['Armas', 'ARMAMENTO'],
        '010' => ['Equipamento tático', 'EQUIPAMENTO'],
    ];

    /**
     * [código da categoria, nome do tipo, perfil]. Toda categoria tem também o tipo "Geral" (GERAL), como na tela.
     * Munição letal e Colete balístico ficam GERAL: os perfis MUNICAO/COLETE exigem fabricante e nível de proteção/fabricação,
     * que os materiais da carga de 2026 não têm (o perfil de tipo em uso não muda depois — ReferenciaService).
     */
    public const TIPOS = [
        ['009', 'Arma de fogo (letal)', 'ARMA_FOGO'],
        ['009', 'Munição letal', 'GERAL'],
        ['009', 'Taser ou equipamento semelhante (não letal)', 'BAIXA_LETALIDADE'],
        ['009', 'Acessório de Taser (não letal)', 'ACESSORIO'],
        ['009', 'Munição de baixa letalidade', 'MUNICAO'],
        ['001', 'Colete balístico', 'GERAL'],
        ['002', 'Rádio', 'GERAL'],
        ['003', 'Algemas e acessórios', 'GERAL'],
        ['010', 'Coldre', 'ACESSORIO'],
        ['010', 'Cinto tático', 'ACESSORIO'],
        ['010', 'Porta-algema', 'ACESSORIO'],
        ['010', 'Porta-carregador', 'ACESSORIO'],
        ['010', 'Fiel retrátil', 'ACESSORIO'],
        ['010', 'Porta-objetos', 'ACESSORIO'],
    ];

    /** Categoria da carga de 2026 (código antigo / chave interna) => [código oficial, tipo oficial, tipo antigo na carga]. */
    public const CARGA = [
        'ARMAMENTO' => ['009', 'Arma de fogo (letal)', 'Pistola'],
        'TASER' => ['009', 'Taser ou equipamento semelhante (não letal)', 'Dispositivo de energia'],
        'ACESSORIO_TASER' => ['009', 'Acessório de Taser (não letal)', 'Acessório de Taser'],
        'MUNICAO' => ['009', 'Munição letal', 'Geral'],
        'COLETE' => ['001', 'Colete balístico', 'Geral'],
        'ALGEMA' => ['003', 'Algemas e acessórios', 'Geral'],
        'COMUNICACAO' => ['002', 'Rádio', 'Geral'],
    ];

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
