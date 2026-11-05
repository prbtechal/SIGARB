<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RF-025, RNF-04, RN-020 — trilha de auditoria append-only com encadeamento de hash (ADR-007).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_auditoria', function (Blueprint $table) {
            $table->id();
            $table->dateTime('ocorrido_em', 6)->index();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->string('acao', 80)->index();
            $table->enum('resultado', ['SUCESSO', 'NEGADO', 'FALHA']);
            $table->string('recurso_tipo', 60)->nullable();
            $table->unsignedBigInteger('recurso_id')->nullable();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 40)->nullable();
            $table->json('dados')->nullable();
            $table->char('hash_anterior', 64);
            $table->char('hash', 64)->unique();
            $table->index(['recurso_tipo', 'recurso_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER trg_auditoria_no_update BEFORE UPDATE ON eventos_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: auditoria append-only'");
            DB::unprepared("CREATE TRIGGER trg_auditoria_no_delete BEFORE DELETE ON eventos_auditoria FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: auditoria append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_auditoria_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_auditoria_no_delete');
        }
        Schema::dropIfExists('eventos_auditoria');
    }
};
