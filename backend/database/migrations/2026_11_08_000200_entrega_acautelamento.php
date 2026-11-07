<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RF-011 (entrega/devolução), RF-015..018 (acautelamento, aprovação, carteira, inspeção), RN-007..011 — REGRA PROVISÓRIA.
return new class extends Migration
{
    private string $sitAntes = "'DISPONIVEL','RESERVADO','ACAUTELADO','MANUTENCAO','BAIXADO','ESTORNADO','EM_TRANSITO','FORA_DO_ESTOQUE'";

    public function up(): void
    {
        Schema::table('movimentacoes', function (Blueprint $t) {
            $t->foreignId('militar_id')->nullable()->after('responsavel_id')->constrained('militares')->restrictOnDelete();
            $t->date('previsao_retorno')->nullable()->after('militar_id');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE itens_individuais MODIFY situacao ENUM({$this->sitAntes},'ENTREGUE') NOT NULL DEFAULT 'DISPONIVEL'");
        }

        Schema::create('acautelamentos', function (Blueprint $t) {
            $t->id();
            $t->char('codigo', 26)->unique(); // ULID público (rotas e carteira)
            $t->foreignId('militar_id')->constrained('militares')->restrictOnDelete();
            $t->foreignId('item_individual_id')->constrained('itens_individuais')->restrictOnDelete();
            $t->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $t->enum('estado', ['SOLICITADO', 'APROVADO', 'REJEITADO', 'CANCELADO', 'ATIVO', 'ENCERRADO'])->default('SOLICITADO');
            $t->foreignId('solicitante_id')->constrained('usuarios')->restrictOnDelete();
            $t->string('justificativa', 500);
            $t->foreignId('aprovador_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $t->dateTime('decidido_em')->nullable();
            $t->string('decisao_justificativa', 500)->nullable();
            $t->foreignId('entrega_id')->nullable()->unique()->constrained('movimentacoes')->restrictOnDelete();
            $t->foreignId('devolucao_id')->nullable()->unique()->constrained('movimentacoes')->restrictOnDelete();
            $t->date('proxima_inspecao')->nullable();
            $t->char('carteira_token', 43)->nullable()->unique(); // aleatório e opaco (P-07); nunca dados pessoais
            $t->unsignedInteger('versao')->default(1);
            $t->timestamps();
            $t->index(['estado', 'proxima_inspecao']);
        });
        // Um item só pode ter um acautelamento em curso (solicitado, aprovado ou ativo).
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE acautelamentos ADD item_em_curso BIGINT UNSIGNED AS (IF(estado IN ('SOLICITADO','APROVADO','ATIVO'), item_individual_id, NULL)) STORED, ADD UNIQUE KEY uq_item_em_curso (item_em_curso)");
        }

        Schema::create('inspecoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('acautelamento_id')->constrained('acautelamentos')->restrictOnDelete();
            $t->foreignId('inspetor_id')->constrained('usuarios')->restrictOnDelete();
            $t->dateTime('realizada_em');
            $t->enum('resultado', ['CONFORME', 'NAO_CONFORME']);
            $t->string('observacao', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER trg_inspecoes_no_update BEFORE UPDATE ON inspecoes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: inspecao imutavel'");
            DB::unprepared("CREATE TRIGGER trg_inspecoes_no_delete BEFORE DELETE ON inspecoes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: inspecao imutavel'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_inspecoes_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_inspecoes_no_delete');
        }
        Schema::dropIfExists('inspecoes');
        Schema::dropIfExists('acautelamentos');
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE itens_individuais MODIFY situacao ENUM({$this->sitAntes}) NOT NULL DEFAULT 'DISPONIVEL'");
        }
        Schema::table('movimentacoes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('militar_id');
            $t->dropColumn('previsao_retorno');
        });
    }
};
