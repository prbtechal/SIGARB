<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RF-029 — listas de referência com desativação segura (nunca exclusão de valor referenciado).
return new class extends Migration
{
    private array $tabelas = ['categorias', 'fabricantes', 'calibres', 'tipos_documento'];

    public function up(): void
    {
        foreach ($this->tabelas as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->boolean('ativo')->default(true)->index();
                $table->timestamp('desativado_em')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tabelas as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['ativo', 'desativado_em']));
        }
    }
};
