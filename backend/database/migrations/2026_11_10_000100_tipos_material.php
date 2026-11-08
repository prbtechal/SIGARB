<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP-12 — tipo de material abaixo da categoria + atributos predefinidos do material.
 * Aditiva: materiais.tipo_id fica NULLABLE; cada categoria existente recebe o tipo "Geral" (perfil GERAL, sem campos
 * obrigatórios novos) e os materiais existentes são associados a ele. Nenhum id ou FK existente muda.
 * down() descarta tipos e atributos cadastrados depois — em base com dados reais, reverter por restauração de backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_material', function (Blueprint $t) {
            $t->id();
            $t->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $t->string('nome', 80);
            $t->string('descricao', 255)->nullable();
            $t->enum('perfil', ['GERAL', 'ARMA_FOGO', 'BAIXA_LETALIDADE', 'COLETE', 'MUNICAO', 'ACESSORIO'])->default('GERAL');
            $t->boolean('ativo')->default(true)->index();
            $t->timestamp('desativado_em')->nullable();
            $t->timestamps();
            $t->unique(['categoria_id', 'nome']);
        });

        Schema::table('materiais', function (Blueprint $t) {
            $t->foreignId('tipo_id')->nullable()->after('categoria_id')->constrained('tipos_material')->restrictOnDelete();
            $t->string('marca', 80)->nullable()->after('modelo');
            $t->string('compatibilidade', 120)->nullable()->after('marca');
            $t->string('cor', 40)->nullable()->after('compatibilidade');
            $t->string('tamanho', 20)->nullable()->after('cor');
        });

        // Backfill determinístico: um tipo "Geral" por categoria; só materiais sem tipo são associados.
        $agora = now();
        foreach (DB::table('categorias')->orderBy('id')->pluck('id') as $cat) {
            DB::table('tipos_material')->insertOrIgnore(['categoria_id' => $cat, 'nome' => 'Geral', 'perfil' => 'GERAL', 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora]);
        }
        DB::table('materiais')->whereNull('tipo_id')->update([
            'tipo_id' => DB::raw("(SELECT t.id FROM tipos_material t WHERE t.categoria_id = materiais.categoria_id AND t.nome = 'Geral')"),
        ]);
    }

    public function down(): void
    {
        Schema::table('materiais', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tipo_id');
            $t->dropColumn(['marca', 'compatibilidade', 'cor', 'tamanho']);
        });
        Schema::dropIfExists('tipos_material');
    }
};
