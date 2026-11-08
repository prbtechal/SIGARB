<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP-12 — dados funcionais e contatos institucionais do militar (todos opcionais; registros existentes seguem válidos).
 * CPF NÃO é coletado (P-09 aberta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('militares', function (Blueprint $t) {
            $t->string('quadro', 60)->nullable()->after('matricula');
            $t->string('funcao', 80)->nullable()->after('quadro');
            $t->string('telefone_institucional', 20)->nullable()->after('funcao');
            $t->string('email_institucional', 120)->nullable()->after('telefone_institucional');
            $t->string('observacoes_administrativas', 1000)->nullable()->after('email_institucional');
        });
    }

    public function down(): void
    {
        Schema::table('militares', fn (Blueprint $t) => $t->dropColumn(['quadro', 'funcao', 'telefone_institucional', 'email_institucional', 'observacoes_administrativas']));
    }
};
