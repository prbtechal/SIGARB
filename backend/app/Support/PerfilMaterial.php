<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * CP-12 — campos predefinidos por perfil do tipo de material (Doc da gestão §3–§4). Sem campos personalizados (RF-MAT-02).
 *
 * Dono de cada fato:
 *  - material (modelo de referência): marca, compatibilidade, cor, tamanho; ficha_tecnica (espécie, funcionamento, acabamento) da arma.
 *  - item físico: fabricado_em, validade, tamanho, nivel_protecao (cada colete tem sua etiqueta).
 *  - lote: validade do lote (inalterado).
 * `modo` = modo de controle exigido (null = qualquer). "AMBOS" = o tipo admite materiais INDIVIDUAL e LOTE, nunca o mesmo material nos dois.
 */
final class PerfilMaterial
{
    public const ATRIBUTOS_MATERIAL = ['marca', 'compatibilidade', 'cor', 'tamanho'];

    public const ATRIBUTOS_ITEM = ['fabricado_em', 'validade', 'tamanho', 'nivel_protecao'];

    // Níveis NIJ 0101.06 usuais; lista provisória até definição institucional.
    public const NIVEIS_PROTECAO = ['IIA', 'II', 'IIIA', 'III', 'IV'];

    public const REGRAS = [
        'GERAL' => ['rotulo' => 'Geral', 'modo' => null, 'material_obrig' => [], 'material_perm' => ['marca', 'cor', 'tamanho'], 'item_obrig' => [], 'item_perm' => []],
        'ARMA_FOGO' => ['rotulo' => 'Arma de fogo', 'modo' => 'INDIVIDUAL', 'material_obrig' => ['fabricante_id', 'modelo', 'calibre_id'], 'material_perm' => ['marca'], 'item_obrig' => ['numero_serie'], 'item_perm' => []],
        'BAIXA_LETALIDADE' => ['rotulo' => 'Equipamento de baixa letalidade', 'modo' => 'INDIVIDUAL', 'material_obrig' => ['fabricante_id', 'modelo'], 'material_perm' => ['marca'], 'item_obrig' => [], 'item_perm' => []],
        'COLETE' => ['rotulo' => 'Colete balístico', 'modo' => 'INDIVIDUAL', 'material_obrig' => ['fabricante_id'], 'material_perm' => ['marca'], 'item_obrig' => ['tamanho', 'nivel_protecao', 'fabricado_em'], 'item_perm' => ['validade']],
        'MUNICAO' => ['rotulo' => 'Munição (por lote)', 'modo' => 'LOTE', 'material_obrig' => ['fabricante_id'], 'material_perm' => ['marca'], 'item_obrig' => [], 'item_perm' => []],
        'ACESSORIO' => ['rotulo' => 'Acessório tático', 'modo' => null, 'material_obrig' => [], 'material_perm' => ['marca', 'compatibilidade', 'cor', 'tamanho'], 'item_obrig' => [], 'item_perm' => []],
    ];

    public const ROTULOS = [
        'fabricante_id' => 'Fabricante', 'modelo' => 'Modelo', 'calibre_id' => 'Calibre', 'numero_serie' => 'Número de série',
        'marca' => 'Marca', 'compatibilidade' => 'Compatibilidade', 'cor' => 'Cor', 'tamanho' => 'Tamanho',
        'fabricado_em' => 'Data de fabricação', 'validade' => 'Validade', 'nivel_protecao' => 'Nível de proteção',
    ];

    public static function perfis(): array
    {
        return array_keys(self::REGRAS);
    }

    private static function vazio(mixed $v): bool
    {
        return $v === null || $v === '';
    }

    /** Valida o estado final do material (já mesclado com o registro existente). Chaves de erro = campos do formulário. */
    public static function validarMaterial(string $perfil, array $m): void
    {
        $r = self::REGRAS[$perfil];
        $erros = [];
        if ($r['modo'] && ($m['modo_controle'] ?? null) !== $r['modo']) {
            $erros['modo_controle'] = ["{$r['rotulo']} exige controle ".($r['modo'] === 'LOTE' ? 'por lote.' : 'individual.')];
        }
        foreach ($r['material_obrig'] as $c) {
            if (self::vazio($m[$c] ?? null)) {
                $erros[$c] = [self::ROTULOS[$c].' é obrigatório para '.mb_strtolower($r['rotulo']).'.'];
            }
        }
        foreach (array_diff(self::ATRIBUTOS_MATERIAL, $r['material_perm']) as $c) {
            if (! self::vazio($m[$c] ?? null)) {
                $erros[$c] = [self::ROTULOS[$c].' não se aplica a '.mb_strtolower($r['rotulo']).'. Limpe o campo.'];
            }
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
    }

    /** Erros de um item individual na entrada (prefixo = "itens.N."). */
    public static function errosItem(?string $perfil, array $i, string $prefixo): array
    {
        $r = self::REGRAS[$perfil ?? 'GERAL'];
        $erros = [];
        foreach ($r['item_obrig'] as $c) {
            if (self::vazio($i[$c] ?? null)) {
                $erros[$prefixo.$c] = [self::ROTULOS[$c].' é obrigatório para '.mb_strtolower($r['rotulo']).'.'];
            }
        }
        foreach (array_diff(self::ATRIBUTOS_ITEM, $r['item_obrig'], $r['item_perm']) as $c) {
            if (! self::vazio($i[$c] ?? null)) {
                $erros[$prefixo.$c] = [self::ROTULOS[$c].' não se aplica a este tipo de material.'];
            }
        }
        if (! self::vazio($i['fabricado_em'] ?? null) && ! self::vazio($i['validade'] ?? null) && $i['validade'] < $i['fabricado_em']) {
            $erros[$prefixo.'validade'] = ['A validade não pode ser anterior à data de fabricação.'];
        }
        if (! self::vazio($i['fabricado_em'] ?? null) && $i['fabricado_em'] > now()->toDateString()) {
            $erros[$prefixo.'fabricado_em'] = ['A data de fabricação não pode estar no futuro.'];
        }

        return $erros;
    }
}
