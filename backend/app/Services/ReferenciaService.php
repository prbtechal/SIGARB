<?php

namespace App\Services;

use App\Models\Calibre;
use App\Models\Categoria;
use App\Models\Fabricante;
use App\Models\TipoDocumento;
use App\Models\TipoMaterial;
use Illuminate\Support\Facades\DB;

/**
 * RF-029 — definição das listas de referência administráveis.
 * Cada entrada declara onde o valor é usado, para bloquear mudanças de significado em valores em uso.
 */
class ReferenciaService
{
    public const LISTAS = [
        'categorias' => ['modelo' => Categoria::class, 'tabela' => 'categorias', 'rotulo' => 'Categorias de material', 'codigo' => true,
            'uso' => [['materiais', 'categoria_id']], 'imutavel_em_uso' => ['natureza', 'codigo']],
        'fabricantes' => ['modelo' => Fabricante::class, 'tabela' => 'fabricantes', 'rotulo' => 'Fabricantes', 'codigo' => false,
            'uso' => [['materiais', 'fabricante_id'], ['lotes', 'fabricante_id']], 'imutavel_em_uso' => []],
        'calibres' => ['modelo' => Calibre::class, 'tabela' => 'calibres', 'rotulo' => 'Calibres', 'codigo' => false,
            'uso' => [['materiais', 'calibre_id']], 'imutavel_em_uso' => []],
        'tipos-documento' => ['modelo' => TipoDocumento::class, 'tabela' => 'tipos_documento', 'rotulo' => 'Tipos de documento', 'codigo' => true,
            'uso' => [['documentos', 'tipo_documento_id']], 'imutavel_em_uso' => ['codigo']],
        // CP-12: perfil e categoria em uso não mudam (alterariam as regras de materiais já cadastrados).
        'tipos-material' => ['modelo' => TipoMaterial::class, 'tabela' => 'tipos_material', 'rotulo' => 'Tipos de material', 'codigo' => false,
            'uso' => [['materiais', 'tipo_id']], 'imutavel_em_uso' => ['perfil', 'categoria_id']],
    ];

    public function definicao(string $tipo): array
    {
        abort_unless(isset(self::LISTAS[$tipo]), 404);

        return self::LISTAS[$tipo];
    }

    public function usos(string $tipo, int $id): int
    {
        return array_sum(array_map(fn ($u) => DB::table($u[0])->where($u[1], $id)->count(), self::LISTAS[$tipo]['uso']));
    }

    /** SQL de contagem de uso por linha (para listagem paginada sem N+1). */
    public function subconsultaUso(string $tipo): string
    {
        $def = self::LISTAS[$tipo];

        return implode(' + ', array_map(fn ($u) => "(SELECT COUNT(*) FROM {$u[0]} WHERE {$u[0]}.{$u[1]} = {$def['tabela']}.id)", $def['uso']));
    }
}
