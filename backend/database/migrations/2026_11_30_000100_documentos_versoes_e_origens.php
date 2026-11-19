<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pedido do responsável (2026-11-19): saída pela tela de saldos, origem da entrada por lista + "Outro…", anexo opcional
// na entrada/saída e tela de Documentos como raiz de busca (anexar a registro sem documento, nova versão do próprio envio).
// Aditiva e idempotente: nada é apagado; o arquivo de cada versão continua imutável (trigger trg_documentos_arquivo_imutavel).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentos', function (Blueprint $t) {
            if (! Schema::hasColumn('documentos', 'versao')) {
                $t->unsignedSmallInteger('versao')->default(1)->after('situacao');
            }
            if (! Schema::hasColumn('documentos', 'versao_anterior_id')) {
                // UNIQUE: cada versão tem no máximo uma sucessora (sem ramificação da cadeia).
                $t->foreignId('versao_anterior_id')->nullable()->after('versao')->unique()->constrained('documentos')->restrictOnDelete();
            }
        });
        // Lista de origens/destinações já usadas (GET /stock/origins): agrupa por tipo e texto.
        if (! $this->temIndice('movimentacoes', 'mov_tipo_origem_idx')) {
            Schema::table('movimentacoes', fn (Blueprint $t) => $t->index(['tipo', 'origem_descricao'], 'mov_tipo_origem_idx'));
        }
    }

    public function down(): void
    {
        if ($this->temIndice('movimentacoes', 'mov_tipo_origem_idx')) {
            Schema::table('movimentacoes', fn (Blueprint $t) => $t->dropIndex('mov_tipo_origem_idx'));
        }
        Schema::table('documentos', function (Blueprint $t) {
            if (Schema::hasColumn('documentos', 'versao_anterior_id')) {
                $t->dropConstrainedForeignId('versao_anterior_id');
            }
            if (Schema::hasColumn('documentos', 'versao')) {
                $t->dropColumn('versao');
            }
        });
    }

    private function temIndice(string $tabela, string $nome): bool
    {
        return collect(Schema::getIndexes($tabela))->contains(fn ($i) => $i['name'] === $nome);
    }
};
