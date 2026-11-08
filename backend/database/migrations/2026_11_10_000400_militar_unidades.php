<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP-12 — vínculos SECUNDÁRIOS do militar com unidades, temporais (inicio/fim). Não concedem acesso ao sistema:
 * o escopo de autorização continua vindo de atribuicoes_papel (ADR-004).
 * Integridade no banco: um vínculo ativo por (militar, unidade) [coluna gerada única]; fim >= inicio;
 * sem DELETE; UPDATE só para encerrar (fim de NULL para data), sem mudar militar/unidade/inicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('militar_unidades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('militar_id')->constrained('militares')->restrictOnDelete();
            $t->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $t->date('inicio');
            $t->date('fim')->nullable();
            $t->foreignId('registrado_por_id')->constrained('usuarios')->restrictOnDelete();
            $t->foreignId('encerrado_por_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $t->timestamps();
            $t->index(['militar_id', 'fim']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE militar_unidades ADD vinculo_ativo VARCHAR(41) AS (IF(fim IS NULL, CONCAT(militar_id, ':', unidade_id), NULL)) STORED, ADD UNIQUE KEY uq_militar_unidade_ativa (vinculo_ativo)");
            DB::statement('ALTER TABLE militar_unidades ADD CONSTRAINT chk_militar_unidade_periodo CHECK (fim IS NULL OR fim >= inicio)');
            DB::unprepared("CREATE TRIGGER trg_militar_unidades_no_delete BEFORE DELETE ON militar_unidades FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: vinculo de unidade nao e apagado'");
            DB::unprepared("CREATE TRIGGER trg_militar_unidades_so_encerra BEFORE UPDATE ON militar_unidades FOR EACH ROW
                IF OLD.fim IS NOT NULL OR NEW.militar_id <> OLD.militar_id OR NEW.unidade_id <> OLD.unidade_id OR NEW.inicio <> OLD.inicio
                   OR NEW.registrado_por_id <> OLD.registrado_por_id
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: vinculo de unidade so pode ser encerrado'; END IF");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_militar_unidades_no_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_militar_unidades_so_encerra');
        }
        Schema::dropIfExists('militar_unidades');
    }
};
