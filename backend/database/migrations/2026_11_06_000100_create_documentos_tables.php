<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RF-014, RN-019, RN-021 — documentos privados com metadados, escopo e vínculos com integridade referencial.
// Classificação de sigilo (P-09) e retenção (P-08) NÃO modeladas: pendentes de decisão institucional.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documento', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nome', 80);
        });

        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->char('codigo_publico', 26)->unique();       // ULID usado nas URLs (não sequencial)
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento')->restrictOnDelete();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete(); // escopo organizacional
            $table->string('titulo', 160);
            $table->string('descricao', 500)->nullable();
            $table->string('numero_referencia', 120)->nullable();
            $table->string('nome_original', 200);                // somente exibição, já saneado
            $table->char('armazenamento_id', 64)->unique();      // nome aleatório no disco privado
            $table->string('mime', 80);
            $table->string('extensao', 10);
            $table->unsignedBigInteger('tamanho_bytes');
            $table->char('sha256', 64)->index();
            $table->enum('situacao', ['ATIVO', 'ARQUIVADO'])->default('ATIVO');
            $table->foreignId('enviado_por_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('arquivado_em')->nullable();
            $table->foreignId('arquivado_por_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->timestamps();
            $table->index(['unidade_id', 'situacao']);
        });

        Schema::create('documento_vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('documento_id')->constrained('documentos')->restrictOnDelete();
            // Uma coluna FK por tipo de entidade (sem polimorfismo genérico). Novos alvos = novas colunas.
            $table->foreignId('movimentacao_id')->nullable()->constrained('movimentacoes')->restrictOnDelete();
            $table->foreignId('vinculado_por_id')->constrained('usuarios')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['documento_id', 'movimentacao_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE documentos ADD CONSTRAINT chk_doc_tamanho CHECK (tamanho_bytes > 0)');
            DB::statement('ALTER TABLE documento_vinculos ADD CONSTRAINT chk_vinculo_um_alvo CHECK (movimentacao_id IS NOT NULL)');
            // Arquivo e checksum são imutáveis depois do envio (metadados descritivos podem mudar, auditados).
            DB::unprepared("CREATE TRIGGER trg_documentos_arquivo_imutavel BEFORE UPDATE ON documentos FOR EACH ROW
                IF NEW.armazenamento_id <> OLD.armazenamento_id OR NEW.sha256 <> OLD.sha256 OR NEW.tamanho_bytes <> OLD.tamanho_bytes
                   OR NEW.mime <> OLD.mime OR NEW.unidade_id <> OLD.unidade_id OR NEW.enviado_por_id <> OLD.enviado_por_id OR NEW.codigo_publico <> OLD.codigo_publico
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: arquivo do documento imutavel'; END IF");
            DB::unprepared("CREATE TRIGGER trg_documentos_no_delete BEFORE DELETE ON documentos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: documento nao e apagado (RN-025)'");
            DB::unprepared("CREATE TRIGGER trg_doc_vinculos_no_update BEFORE UPDATE ON documento_vinculos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: vinculo imutavel'");
            DB::unprepared("CREATE TRIGGER trg_doc_vinculos_no_delete BEFORE DELETE ON documento_vinculos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: vinculo imutavel'");
        }
    }

    public function down(): void
    {
        foreach (['trg_documentos_arquivo_imutavel', 'trg_documentos_no_delete', 'trg_doc_vinculos_no_update', 'trg_doc_vinculos_no_delete'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS $t");
        }
        Schema::dropIfExists('documento_vinculos');
        Schema::dropIfExists('documentos');
        Schema::dropIfExists('tipos_documento');
    }
};
