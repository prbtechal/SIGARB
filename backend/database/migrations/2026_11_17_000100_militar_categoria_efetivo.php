<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tipo de efetivo (mesmas categorias do Sistema de Escalas). NULL = não informado: não recebe material letal até ser informado. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('militares', function (Blueprint $t) {
            $t->enum('categoria_efetivo', ['RR', 'R1', 'VIGILANTE', 'PM_ATIVA', 'BM_ATIVA'])->nullable()->after('situacao');
        });
    }

    public function down(): void
    {
        Schema::table('militares', fn (Blueprint $t) => $t->dropColumn('categoria_efetivo'));
    }
};
