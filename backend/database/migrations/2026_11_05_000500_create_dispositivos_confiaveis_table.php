<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RF-001/P-11 — dispositivo com login bem-sucedido anterior; dispensa apenas o limite por conta (anti-DoS).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispositivos_confiaveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256 do token; o token só existe no cookie
            $table->timestamp('criado_em');
            $table->timestamp('usado_em');
            $table->timestamp('revogado_em')->nullable();
            $table->index(['usuario_id', 'revogado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_confiaveis');
    }
};
