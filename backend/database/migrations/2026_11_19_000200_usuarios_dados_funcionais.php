<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados funcionais da própria conta, para quem não é efetivo cadastrado (ex.: civil "Auxiliar Administrativo").
 * Todos opcionais; posto vazio = "Civil / sem posto". Matrícula única quando preenchida (NULL não conflita).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->foreignId('posto_graduacao_id')->nullable()->after('militar_id')->constrained('postos_graduacoes')->restrictOnDelete();
            $t->string('cargo', 80)->nullable()->after('posto_graduacao_id');
            $t->string('matricula', 30)->nullable()->unique()->after('cargo');
            $t->string('telefone', 20)->nullable()->after('matricula');
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->dropConstrainedForeignId('posto_graduacao_id');
            $t->dropUnique(['matricula']);
            $t->dropColumn(['cargo', 'matricula', 'telefone']);
        });
    }
};
