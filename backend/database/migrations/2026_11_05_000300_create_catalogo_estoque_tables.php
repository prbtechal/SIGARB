<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// RF-007/008/009 (catálogo, individual × lote), RF-010/011/013 (saldo × razão), RN-001..004, RN-012, RN-017/018.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nome', 80);
            $table->enum('natureza', ['ARMAMENTO', 'MUNICAO', 'EQUIPAMENTO', 'PRODUTO', 'TREINAMENTO']);
        });
        Schema::create('fabricantes', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120)->unique();
        });
        Schema::create('calibres', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 40)->unique();
        });

        Schema::create('materiais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->foreignId('fabricante_id')->nullable()->constrained('fabricantes')->restrictOnDelete();
            $table->foreignId('calibre_id')->nullable()->constrained('calibres')->restrictOnDelete();
            $table->string('codigo', 40)->unique();
            $table->string('nome', 160);
            $table->string('modelo', 120)->nullable();
            $table->enum('modo_controle', ['INDIVIDUAL', 'LOTE']); // P-04: definido por material
            $table->string('unidade_medida', 20)->default('UN');
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materiais')->restrictOnDelete();
            $table->string('codigo', 60);
            $table->foreignId('fabricante_id')->nullable()->constrained('fabricantes')->restrictOnDelete();
            $table->date('validade')->nullable();
            $table->timestamps();
            $table->unique(['material_id', 'codigo']);
        });

        Schema::create('itens_individuais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materiais')->restrictOnDelete();
            $table->string('identificador', 60)->unique(); // RN-003
            $table->string('numero_serie', 80)->nullable();
            $table->foreignId('local_estoque_id')->constrained('locais_estoque')->restrictOnDelete();
            $table->enum('situacao', ['DISPONIVEL', 'RESERVADO', 'ACAUTELADO', 'MANUTENCAO', 'BAIXADO', 'ESTORNADO'])->default('DISPONIVEL');
            $table->string('estado_conservacao', 30)->default('BOM');
            $table->timestamps();
            $table->unique(['material_id', 'numero_serie']); // série única por material quando informada
        });

        Schema::create('saldos_estoque', function (Blueprint $table) {
            $table->id();
            $table->foreignId('local_estoque_id')->constrained('locais_estoque')->restrictOnDelete();
            $table->foreignId('lote_id')->constrained('lotes')->restrictOnDelete();
            $table->unsignedBigInteger('quantidade')->default(0);
            $table->unsignedBigInteger('quantidade_reservada')->default(0);
            $table->timestamps();
            $table->unique(['local_estoque_id', 'lote_id']);
        });

        Schema::create('movimentacoes', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['ENTRADA', 'ESTORNO', 'SAIDA', 'ENTREGA', 'DEVOLUCAO', 'TRANSFERENCIA', 'RESERVA', 'BAIXA', 'AJUSTE']);
            $table->foreignId('local_origem_id')->nullable()->constrained('locais_estoque')->restrictOnDelete();
            $table->foreignId('local_destino_id')->nullable()->constrained('locais_estoque')->restrictOnDelete();
            $table->foreignId('responsavel_id')->constrained('usuarios')->restrictOnDelete();
            $table->dateTime('ocorrido_em', 6);
            $table->string('origem_descricao', 255)->nullable();
            $table->string('documento_referencia', 120)->nullable();
            $table->string('justificativa', 500)->nullable();
            $table->string('chave_idempotencia', 80)->unique();
            $table->char('hash_requisicao', 64);
            $table->foreignId('estorno_de_id')->nullable()->unique()->constrained('movimentacoes')->restrictOnDelete();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['tipo', 'ocorrido_em']);
        });

        Schema::create('movimentacao_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimentacao_id')->constrained('movimentacoes')->restrictOnDelete();
            $table->foreignId('item_individual_id')->nullable()->constrained('itens_individuais')->restrictOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $table->unsignedBigInteger('quantidade');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE saldos_estoque ADD CONSTRAINT chk_saldo_reserva CHECK (quantidade_reservada <= quantidade)');
            DB::statement('ALTER TABLE movimentacao_itens ADD CONSTRAINT chk_item_xor_lote CHECK ((item_individual_id IS NULL) <> (lote_id IS NULL))');
            DB::statement('ALTER TABLE movimentacao_itens ADD CONSTRAINT chk_qtd_positiva CHECK (quantidade > 0 AND (item_individual_id IS NULL OR quantidade = 1))');
            foreach (['movimentacoes', 'movimentacao_itens'] as $t) {
                // RN-017: o razão não é alterado nem apagado por operações comuns.
                DB::unprepared("CREATE TRIGGER trg_{$t}_no_update BEFORE UPDATE ON {$t} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: razao imutavel ({$t})'");
                DB::unprepared("CREATE TRIGGER trg_{$t}_no_delete BEFORE DELETE ON {$t} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SIGARB: razao imutavel ({$t})'");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            foreach (['movimentacoes', 'movimentacao_itens'] as $t) {
                DB::unprepared("DROP TRIGGER IF EXISTS trg_{$t}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS trg_{$t}_no_delete");
            }
        }
        foreach (['movimentacao_itens', 'movimentacoes', 'saldos_estoque', 'itens_individuais', 'lotes', 'materiais', 'calibres', 'fabricantes', 'categorias'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
