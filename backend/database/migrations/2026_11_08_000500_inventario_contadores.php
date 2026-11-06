<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
    {
        Schema::dropIfExists('inventario_contadores');
    }
};
