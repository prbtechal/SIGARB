<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RF-020 / RN-016 — inventário com contagem, divergências e ajuste aprovado por outra pessoa (REGRA PROVISÓRIA).
// RF-007 — ficha técnica de armamento (campos provisórios, P-04).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventarios', function (Blueprint $t) {
            $t->id();
            $t->char('codigo', 26)->unique();
            $t->foreignId('local_estoque_id')->constrained('locais_estoque')->restrictOnDelete();
            $t->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $t->enum('estado', ['EM_CONTAGEM', 'AGUARDANDO_APROVACAO', 'AJUSTADO', 'REJEITADO', 'CANCELADO'])->default('EM_CONTAGEM');
            $t->foreignId('aberto_por')->constrained('usuarios')->restrictOnDelete();
            $t->foreignId('contado_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $t->foreignId('decidido_por')->nullable()->constrained('usuarios')->restrictOnDelete();
            $t->dateTime('decidido_em')->nullable();
            $t->string('observacao', 500)->nullable();
            // Ajuste em até duas movimentações AJUSTE: falta (local_origem) e sobra (local_destino).
            $t->foreignId('ajuste_falta_id')->nullable()->unique()->constrained('movimentacoes')->restrictOnDelete();
            $t->foreignId('ajuste_sobra_id')->nullable()->unique()->constrained('movimentacoes')->restrictOnDelete();
            $t->unsignedInteger('versao')->default(1);
            $t->timestamps();
        });
        // Um inventário em curso por local.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventarios ADD local_em_curso BIGINT UNSIGNED AS (IF(estado IN ('EM_CONTAGEM','AGUARDANDO_APROVACAO'), local_estoque_id, NULL)) STORED, ADD UNIQUE KEY uq_local_em_curso (local_em_curso)");
        }
        Schema::create('inventario_itens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inventario_id')->constrained('inventarios')->restrictOnDelete();
            $t->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $t->foreignId('item_individual_id')->nullable()->constrained('itens_individuais')->restrictOnDelete();
            $t->unsignedBigInteger('esperado');
            $t->unsignedBigInteger('contado')->nullable();
            $t->unique(['inventario_id', 'lote_id']);
            $t->unique(['inventario_id', 'item_individual_id']);
        });
        Schema::table('materiais', function (Blueprint $t) {
            $t->json('ficha_tecnica')->nullable()->after('unidade_medida');
        });
    }

    public function down(): void
    {
        Schema::table('materiais', fn (Blueprint $t) => $t->dropColumn('ficha_tecnica'));
        Schema::dropIfExists('inventario_itens');
        Schema::dropIfExists('inventarios');
    }
};
