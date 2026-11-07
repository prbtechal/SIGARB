<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Perfil do efetivo: foto em disco privado (sem metadados); caminho aleatório, nunca servido direto pelo servidor web.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('militares', function (Blueprint $t) {
            $t->string('foto_caminho', 120)->nullable()->after('situacao');
            $t->timestamp('foto_atualizada_em')->nullable()->after('foto_caminho');
        });
    }

    public function down(): void
    {
        Schema::table('militares', fn (Blueprint $t) => $t->dropColumn(['foto_caminho', 'foto_atualizada_em']));
    }
};
