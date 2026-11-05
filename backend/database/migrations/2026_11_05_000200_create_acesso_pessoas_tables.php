<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RF-003/RN-024 (papéis, permissões, escopo) e RF-005/§2.1 (militar separado do usuário; vínculo opcional 0..1:1).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('papeis', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40)->unique();
            $table->string('nome', 80);
            $table->string('descricao', 255)->nullable();
        });

        Schema::create('permissoes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('descricao', 255);
        });

        Schema::create('papel_permissao', function (Blueprint $table) {
            $table->foreignId('papel_id')->constrained('papeis')->cascadeOnDelete();
            $table->foreignId('permissao_id')->constrained('permissoes')->cascadeOnDelete();
            $table->primary(['papel_id', 'permissao_id']);
        });

        Schema::create('atribuicoes_papel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('papel_id')->constrained('papeis')->restrictOnDelete();
            // NULL = escopo global (ADR-004).
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete();
            $table->foreignId('concedido_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();
            $table->unique(['usuario_id', 'papel_id', 'unidade_id']);
        });

        Schema::create('postos_graduacoes', function (Blueprint $table) {
            $table->id();
            $table->string('sigla', 20)->unique();
            $table->string('nome', 80);
            $table->unsignedSmallInteger('ordem');
        });

        Schema::create('militares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete();
            $table->foreignId('posto_graduacao_id')->constrained('postos_graduacoes')->restrictOnDelete();
            $table->string('nome', 160);
            $table->string('nome_guerra', 60);
            $table->string('matricula', 30)->unique();
            $table->enum('situacao', ['ATIVO', 'AFASTADO', 'INATIVO'])->default('ATIVO');
            $table->timestamps();
        });

        Schema::table('usuarios', function (Blueprint $table) {
            // Vínculo opcional e controlado: no máximo um usuário por militar.
            $table->foreignId('militar_id')->nullable()->unique()->after('email')->constrained('militares')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropConstrainedForeignId('militar_id'));
        Schema::dropIfExists('militares');
        Schema::dropIfExists('postos_graduacoes');
        Schema::dropIfExists('atribuicoes_papel');
        Schema::dropIfExists('papel_permissao');
        Schema::dropIfExists('permissoes');
        Schema::dropIfExists('papeis');
    }
};
