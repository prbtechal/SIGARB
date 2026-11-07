<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Todos que já salvaram contagem no inventário (append-only): nenhum deles aprova o ajuste,
// mesmo que outra pessoa tenha sobrescrito a linha depois.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventario_contadores', function (Blueprint $t) {
            $t->foreignId('inventario_id')->constrained('inventarios')->restrictOnDelete();
            $t->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $t->primary(['inventario_id', 'usuario_id']);
        });
        // Backfill: contadores já gravados por linha passam a constar no registro.
        DB::table('inventario_contadores')->insertOrIgnore(
            DB::table('inventario_itens')->whereNotNull('contado_por')->distinct()->get(['inventario_id', 'contado_por as usuario_id'])
                ->map(fn ($r) => (array) $r)->all()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_contadores');
    }
};
