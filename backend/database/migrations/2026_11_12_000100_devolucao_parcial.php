<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CP-13 (P-05, decisão do responsável): devolução parcial. A devolução que zera o restante continua usando vinculo_id (UNIQUE,
// conclui a entrega); as parciais apontam para a entrega por vinculo_parcial_id (não único). Movimentações seguem imutáveis.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimentacoes', function (Blueprint $t) {
            $t->foreignId('vinculo_parcial_id')->nullable()->after('vinculo_id')->constrained('movimentacoes')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('vinculo_parcial_id');
        });
    }
};
