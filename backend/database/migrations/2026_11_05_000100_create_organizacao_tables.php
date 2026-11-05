<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RF-006 — organização, unidades/subunidades (árvore) e locais de estoque (reserva/depósito). Hierarquia real: P-02.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizacoes', function (Blueprint $table) {
            $table->id();
            $table->string('sigla', 20)->unique();
            $table->string('nome', 160);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('unidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacao_id')->constrained('organizacoes')->restrictOnDelete();
            $table->foreignId('unidade_superior_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $table->string('sigla', 30);
            $table->string('nome', 160);
            $table->enum('tipo', ['UNIDADE', 'SUBUNIDADE']);
            // Caminho materializado "/1/4/9/" para consultas de subárvore (ADR-004).
            $table->string('caminho', 500)->default('')->index();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['organizacao_id', 'sigla']);
        });

        Schema::create('locais_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('nome', 160);
            $table->enum('tipo', ['RESERVA', 'DEPOSITO']);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locais_estoque');
        Schema::dropIfExists('unidades');
        Schema::dropIfExists('organizacoes');
    }
};
