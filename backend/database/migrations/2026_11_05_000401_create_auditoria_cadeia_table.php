<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-007 — ponteiro único do fim da cadeia de hash; serializa a escrita de auditoria.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria_cadeia', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->char('ultimo_hash', 64);
        });
        DB::table('auditoria_cadeia')->insert(['id' => 1, 'ultimo_hash' => str_repeat('0', 64)]);
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_cadeia');
    }
};
