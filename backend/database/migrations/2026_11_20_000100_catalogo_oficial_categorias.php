<?php

use App\Models\Categoria;
use App\Models\Material;
use App\Models\TipoMaterial;
use App\Services\AuditoriaService;
use App\Support\CatalogoOficial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo oficial do gestor (docs/requisitos/CATALOGO-OFICIAL.md).
 *
 * 1. Garante as 10 categorias 001..010; categoria já existente com o código (ex.: 004 Treinamento, 005 Sinalização) não é alterada.
 * 2. Garante "Geral" + os tipos do documento em cada categoria (tipo existente com o mesmo nome não é alterado).
 * 3. Move os materiais da carga de 2026 (categorias ARMAMENTO, TASER, MUNICAO, COLETE, ALGEMA, COMUNICACAO) para a
 *    categoria/tipo oficial — só materiais.categoria_id/tipo_id (materiais não têm trigger de imutabilidade).
 *    Itens, lotes, saldos e movimentações referenciam o material por id e não são tocados. Tipo sem mapeamento é levado
 *    com o mesmo nome e perfil; mapeamento que mudaria o perfil aborta (mudaria as regras de material já cadastrado).
 * 4. Apaga as categorias/tipos da carga que ficaram sem material.
 * Cada mudança gera evento pelo AuditoriaService (mesmas ações da tela). Idempotente: rodar de novo não muda nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $aud = app(AuditoriaService::class);
        DB::transaction(function () use ($aud) {
            $cat = [];
            foreach (CatalogoOficial::CATEGORIAS as $codigo => [$nome, $natureza]) {
                $c = Categoria::firstOrCreate(['codigo' => $codigo], ['nome' => $nome, 'natureza' => $natureza, 'ativo' => true]);
                if ($c->wasRecentlyCreated) {
                    $aud->registrar('referencia.categorias.criada', 'SUCESSO', 'categorias', $c->id, dados: ['codigo' => $codigo, 'nome' => $nome, 'natureza' => $natureza, 'origem' => 'catalogo-oficial']);
                }
                $cat[$codigo] = $c;
                $this->tipo($aud, $c, 'Geral', 'GERAL');
            }
            foreach (CatalogoOficial::TIPOS as [$codigo, $nome, $perfil]) {
                $this->tipo($aud, $cat[$codigo], $nome, $perfil);
            }

            $antigas = Categoria::whereIn('codigo', array_keys(CatalogoOficial::CARGA))->get();
            foreach ($antigas as $velha) {
                foreach (Material::with('tipo')->where('categoria_id', $velha->id)->orderBy('id')->get() as $m) {
                    $chave = $velha->codigo === 'TASER' && $m->tipo?->nome === 'Acessório de Taser' ? 'ACESSORIO_TASER' : $velha->codigo;
                    [$destino, $tipoOficial, $tipoCarga] = CatalogoOficial::CARGA[$chave];
                    $perfil = $m->tipo?->perfil ?? 'GERAL';
                    $nomeTipo = ($m->tipo?->nome ?? 'Geral') === $tipoCarga ? $tipoOficial : ($m->tipo?->nome ?? 'Geral');
                    $novo = $this->tipo($aud, $cat[$destino], $nomeTipo, $perfil);
                    if ($novo->perfil !== $perfil) {
                        throw new RuntimeException("Material {$m->codigo}: tipo \"{$nomeTipo}\" tem perfil {$novo->perfil}, material está em {$perfil}.");
                    }
                    $antes = ['categoria_id' => $m->categoria_id, 'tipo_id' => $m->tipo_id];
                    $depois = ['categoria_id' => $cat[$destino]->id, 'tipo_id' => $novo->id];
                    $m->forceFill($depois)->save();
                    $aud->registrar('material.atualizado', 'SUCESSO', 'material', $m->id, dados: ['antes' => $antes, 'depois' => $depois, 'motivo' => 'catalogo-oficial']);
                }
                foreach (TipoMaterial::where('categoria_id', $velha->id)->get() as $t) {
                    if (! Material::where('tipo_id', $t->id)->exists()) {
                        $t->delete();
                        $aud->registrar('referencia.tipos-material.removida', 'SUCESSO', 'tipos-material', $t->id, dados: ['categoria_id' => $velha->id, 'nome' => $t->nome, 'motivo' => 'catalogo-oficial']);
                    }
                }
                if (! Material::where('categoria_id', $velha->id)->exists() && ! TipoMaterial::where('categoria_id', $velha->id)->exists()) {
                    $velha->delete();
                    $aud->registrar('referencia.categorias.removida', 'SUCESSO', 'categorias', $velha->id, dados: ['codigo' => $velha->codigo, 'nome' => $velha->nome, 'motivo' => 'catalogo-oficial']);
                }
            }
        });
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
        // Não destrutivo de propósito: materiais já podem ter sido cadastrados nas categorias oficiais, e devolver os
        // materiais às categorias da carga apagaria a reclassificação auditada. Reverter = restauração de backup.
    }
};
