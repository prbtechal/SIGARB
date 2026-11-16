<?php

use App\Models\Categoria;
use App\Models\Material;
use App\Models\TipoMaterial;
use App\Services\AuditoriaService;
use App\Support\CatalogoOficial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisão do responsável (2026-11-16): cartucho de Taser é munição. Material de 009 Armas cujo nome começa por
 * "Cartucho(s)" vai para 011 Munição / "Cartucho de Taser (não letal)" (perfil ACESSORIO, o mesmo de origem; perfil
 * diferente aborta). Natureza MUNICAO é letal (Militar::NATUREZAS_LETAIS): continua restrito a RR e PM/BM da ativa,
 * como já era em Armas. Só materiais.categoria_id/tipo_id mudam, com auditoria. Idempotente; down() não desfaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        $aud = app(AuditoriaService::class);
        DB::transaction(function () use ($aud) {
            $armas = Categoria::where('codigo', '009')->first();
            $mun = Categoria::where('codigo', '011')->first();
            if (! $armas || ! $mun) {
                return;
            }
            $nome = 'Cartucho de Taser (não letal)';
            $perfilAlvo = CatalogoOficial::perfil('011', $nome);
            $tipo = TipoMaterial::firstOrCreate(['categoria_id' => $mun->id, 'nome' => $nome], ['perfil' => $perfilAlvo, 'ativo' => true]);
            if ($tipo->wasRecentlyCreated) {
                $aud->registrar('referencia.tipos-material.criada', 'SUCESSO', 'tipos-material', $tipo->id, dados: ['categoria_id' => $mun->id, 'nome' => $nome, 'perfil' => $perfilAlvo, 'origem' => 'catalogo-oficial']);
            }
            foreach (Material::with('tipo')->where('categoria_id', $armas->id)->orderBy('id')->get() as $m) {
                if (! CatalogoOficial::ehCartucho($m->nome)) {
                    continue;
                }
                $perfil = $m->tipo?->perfil ?? 'GERAL';
                if ($tipo->perfil !== $perfil) {
                    throw new RuntimeException("Material {$m->codigo}: tipo \"{$nome}\" tem perfil {$tipo->perfil}, material está em {$perfil}.");
                }
                $antes = ['categoria_id' => $m->categoria_id, 'tipo_id' => $m->tipo_id];
                $depois = ['categoria_id' => $mun->id, 'tipo_id' => $tipo->id];
                $m->forceFill($depois)->save();
                $aud->registrar('material.atualizado', 'SUCESSO', 'material', $m->id, dados: ['antes' => $antes, 'depois' => $depois, 'motivo' => 'cartuchos-municao']);
            }
        });
    }

    public function down(): void
    {
        // Não destrutivo: reverter = restauração de backup.
    }
};
