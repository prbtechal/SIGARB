<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central de Suporte. Chamados são dados de SUPORTE, sem vínculo com registros de estoque/militares.
 * - `codigo` (ULID) é o único identificador exposto na API (sem enumeração); `protocolo` é o rótulo humano.
 * - `versao` dá controle de concorrência otimista nas ações de atendimento.
 * - Mensagens são append-only (triggers), como a trilha de auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chamados', function (Blueprint $t) {
            $t->id();
            $t->char('codigo', 26)->unique();
            $t->string('protocolo', 24)->unique();
            $t->foreignId('solicitante_id')->constrained('usuarios')->restrictOnDelete();
            $t->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $t->string('categoria', 20);
            $t->string('prioridade', 10);
            $t->string('status', 24)->index();
            $t->string('assunto', 150);
            $t->foreignId('atribuido_a_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $t->json('diagnostico')->nullable();
            $t->unsignedInteger('versao')->default(1);
            $t->unsignedTinyInteger('reaberturas')->default(0);
            $t->timestamp('resolvido_em')->nullable();
            $t->timestamps();
            $t->index(['solicitante_id', 'status']);
            $t->index(['atribuido_a_id', 'status']);
        });

        Schema::create('chamado_mensagens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chamado_id')->constrained('chamados')->restrictOnDelete();
            $t->foreignId('autor_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $t->string('tipo', 10); // PUBLICA | INTERNA | SISTEMA
            $t->text('corpo');
            $t->boolean('redigido')->default(false);
            $t->timestamp('created_at', 6)->useCurrent();
            $t->index(['chamado_id', 'id']);
        });

        Schema::create('notificacoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $t->string('tipo', 40);
            $t->foreignId('chamado_id')->nullable()->constrained('chamados')->restrictOnDelete();
            $t->string('texto', 200);
            $t->timestamp('lida_em')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['usuario_id', 'lida_em']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE chamados ADD CONSTRAINT chk_chamado_status CHECK (status IN ('ABERTO','EM_ATENDIMENTO','AGUARDANDO_SOLICITANTE','RESOLVIDO','CANCELADO'))");
            DB::statement("ALTER TABLE chamados ADD CONSTRAINT chk_chamado_prioridade CHECK (prioridade IN ('BAIXA','MEDIA','ALTA','CRITICA'))");
            DB::statement("ALTER TABLE chamado_mensagens ADD CONSTRAINT chk_msg_tipo CHECK (tipo IN ('PUBLICA','INTERNA','SISTEMA'))");
            DB::unprepared("CREATE TRIGGER trg_chamado_msg_no_update BEFORE UPDATE ON chamado_mensagens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: mensagem de chamado imutavel'");
            DB::unprepared("CREATE TRIGGER trg_chamado_msg_no_delete BEFORE DELETE ON chamado_mensagens FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: mensagem de chamado imutavel'");
            DB::unprepared("CREATE TRIGGER trg_chamados_no_delete BEFORE DELETE ON chamados FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: chamado nao e apagado'");
            DB::unprepared("CREATE TRIGGER trg_chamados_identidade BEFORE UPDATE ON chamados FOR EACH ROW
                IF NEW.codigo <> OLD.codigo OR NEW.protocolo <> OLD.protocolo OR NEW.solicitante_id <> OLD.solicitante_id OR NOT (NEW.unidade_id <=> OLD.unidade_id)
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: identidade do chamado imutavel'; END IF");
        }
    }

    public function down(): void
    {
        foreach (['trg_chamado_msg_no_update', 'trg_chamado_msg_no_delete', 'trg_chamados_no_delete', 'trg_chamados_identidade'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS $t");
        }
        Schema::dropIfExists('notificacoes');
        Schema::dropIfExists('chamado_mensagens');
        Schema::dropIfExists('chamados');
    }
};
