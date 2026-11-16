<?php

use App\Models\Categoria;
use App\Models\Material;
use App\Models\TipoMaterial;
use App\Services\AuditoriaService;
use App\Support\CatalogoOficial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisões do responsável (2026-11-16), docs/requisitos/CATALOGO-OFICIAL.md:
 *
 * 1. Munição tem categoria própria: 011 Munição (natureza MUNICAO, prefixo MUN). Os tipos "Munição letal" e
 *    "Munição de baixa letalidade" saem de 009 Armas; os materiais deles vão para o tipo de mesmo nome em 011.
 *    Se já existir UMA categoria de natureza MUNICAO fora do catálogo (cadastro do gestor) e nenhuma 011, ela é
 *    adotada como 011 (só o código e o prefixo vazio mudam; nome mantido). Mais de uma: aborta para decisão manual.
 * 2. Baterias são equipamento: material de 009 Armas cujo nome começa por "Bateria(s)" vai para 010 Equipamento
 *    tático / Bateria (perfil ACESSORIO, o mesmo do tipo de origem; perfil diferente aborta).
 *
 * Só materiais.categoria_id/tipo_id mudam. Itens, lotes, saldos e movimentações referenciam o material por id; os
 * identificadores já emitidos (ARM-…) não mudam (o código do item é fixo, ver identificador automático).
 * Tipos de 009 que ficarem sem material são apagados. Tudo gera evento de auditoria. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        $aud = app(AuditoriaService::class);
        DB::transaction(function () use ($aud) {
            $mun = $this->categoriaMunicao($aud);
            $tat = Categoria::where('codigo', '010')->first();
            $armas = Categoria::where('codigo', '009')->first();
            $this->tipo($aud, $mun, 'Geral', 'GERAL');
            foreach (CatalogoOficial::TIPOS as [$codigo, $nome, $perfil]) {
                if ($codigo === '011') {
                    $this->tipo($aud, $mun, $nome, $perfil);
                }
            }
            if (! $armas) {
                return;
            }

            foreach (TipoMaterial::where('categoria_id', $armas->id)->whereIn('nome', CatalogoOficial::TIPOS_MUNICAO)->get() as $velho) {
                $novo = $this->tipo($aud, $mun, $velho->nome, $velho->perfil);
                foreach (Material::where('tipo_id', $velho->id)->orderBy('id')->get() as $m) {
                    $this->mover($aud, $m, $mun, $novo, 'categoria-municao');
                }
            }

            if ($tat) {
                foreach (Material::with('tipo')->where('categoria_id', $armas->id)->orderBy('id')->get() as $m) {
                    if (! CatalogoOficial::ehBateria($m->nome)) {
                        continue;
                    }
                    $novo = $this->tipo($aud, $tat, 'Bateria', CatalogoOficial::perfil('010', 'Bateria'));
                    $perfil = $m->tipo?->perfil ?? 'GERAL';
                    if ($novo->perfil !== $perfil) {
                        throw new RuntimeException("Material {$m->codigo}: tipo \"Bateria\" tem perfil {$novo->perfil}, material está em {$perfil}.");
                    }
                    $this->mover($aud, $m, $tat, $novo, 'baterias-equipamento');
                }
            }

            foreach (TipoMaterial::where('categoria_id', $armas->id)->whereIn('nome', CatalogoOficial::TIPOS_MUNICAO)->get() as $t) {
                if (! Material::where('tipo_id', $t->id)->exists()) {
                    $t->delete();
                    $aud->registrar('referencia.tipos-material.removida', 'SUCESSO', 'tipos-material', $t->id, dados: ['categoria_id' => $armas->id, 'nome' => $t->nome, 'motivo' => 'categoria-municao']);
                }
            }
        });
    }

    private function categoriaMunicao(AuditoriaService $aud): Categoria
    {
        [$nome, $natureza, $prefixo] = CatalogoOficial::CATEGORIAS['011'];
        $c = Categoria::where('codigo', '011')->first();
        if (! $c) {
            $existentes = Categoria::where('natureza', 'MUNICAO')->whereNotIn('codigo', array_keys(CatalogoOficial::CATEGORIAS))->get();
            if ($existentes->count() > 1) {
                throw new RuntimeException('Há mais de uma categoria de natureza MUNICAO ('.$existentes->pluck('codigo')->join(', ').'): decidir qual vira 011.');
            }
            if ($c = $existentes->first()) {
                $antes = $c->only(['codigo', 'prefixo_codigo']);
                $c->forceFill(['codigo' => '011'])->save();
                $aud->registrar('referencia.categorias.atualizada', 'SUCESSO', 'categorias', $c->id, dados: ['antes' => $antes, 'depois' => ['codigo' => '011'], 'motivo' => 'categoria-municao']);
            } else {
                $c = Categoria::create(['codigo' => '011', 'nome' => $nome, 'natureza' => $natureza, 'ativo' => true]);
                $aud->registrar('referencia.categorias.criada', 'SUCESSO', 'categorias', $c->id, dados: ['codigo' => '011', 'nome' => $nome, 'natureza' => $natureza, 'origem' => 'catalogo-oficial']);
            }
        }
        if ($c->prefixo_codigo === null && ! Categoria::where('prefixo_codigo', $prefixo)->exists()) {
            $c->forceFill(['prefixo_codigo' => $prefixo])->save();
        }

        return $c;
    }

    private function mover(AuditoriaService $aud, Material $m, Categoria $c, TipoMaterial $t, string $motivo): void
    {
        $antes = ['categoria_id' => $m->categoria_id, 'tipo_id' => $m->tipo_id];
        $depois = ['categoria_id' => $c->id, 'tipo_id' => $t->id];
        if ($antes === $depois) {
            return;
        }
        $m->forceFill($depois)->save();
        $aud->registrar('material.atualizado', 'SUCESSO', 'material', $m->id, dados: ['antes' => $antes, 'depois' => $depois, 'motivo' => $motivo]);
    }

    private function tipo(AuditoriaService $aud, Categoria $c, string $nome, string $perfil): TipoMaterial
    {
        $t = TipoMaterial::firstOrCreate(['categoria_id' => $c->id, 'nome' => $nome], ['perfil' => $perfil, 'ativo' => true]);
        if ($t->wasRecentlyCreated) {
            $aud->registrar('referencia.tipos-material.criada', 'SUCESSO', 'tipos-material', $t->id, dados: ['categoria_id' => $c->id, 'nome' => $nome, 'perfil' => $perfil, 'origem' => 'catalogo-oficial']);
        }

        return $t;
    }

    public function down(): void
    {
        // Não destrutivo, como a 2026_11_20_000100: reverter = restauração de backup.
    }
};
