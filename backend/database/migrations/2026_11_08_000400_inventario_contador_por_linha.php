<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Segregação de funções: quem contou qualquer linha não aprova o ajuste do inventário.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventario_itens', function (Blueprint $t) {
            $t->foreignId('contado_por')->nullable()->after('contado')->constrained('usuarios')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventario_itens', fn (Blueprint $t) => $t->dropConstrainedForeignId('contado_por'));
    }
};
