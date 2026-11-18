<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisão do responsável (2026-11-18): quando a categoria de um efetivo passa a RR/PM_ATIVA/BM_ATIVA e o posto continua CIV,
 * a T.I. (militares.gerir ou usuarios.gerir no escopo) recebe uma notificação in-app ligada ao efetivo.
 * Aditiva: uma coluna nula em notificacoes (permite não duplicar o aviso aberto do mesmo efetivo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notificacoes', function (Blueprint $t) {
            $t->foreignId('militar_id')->nullable()->after('pedido_material_id')->constrained('militares')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notificacoes', fn (Blueprint $t) => $t->dropConstrainedForeignId('militar_id'));
    }
};
