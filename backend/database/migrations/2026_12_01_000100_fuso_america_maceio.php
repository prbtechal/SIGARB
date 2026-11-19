<?php

use App\Services\ConversaoFusoService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ADR-015 — o Arsenal passa a operar em America/Maceio (UTC-3), como Escala/Ronda/Visita e a VM.
// Converte o histórico gravado em UTC (-3 h) uma única vez; banco criado neste mesmo migrate só recebe a marca.
// DDL (DROP/CREATE TRIGGER) faz commit implícito: a migração não é atômica, mas é retomável (marca por tabela).
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable(ConversaoFusoService::TABELA_MARCA)) {
            Schema::create(ConversaoFusoService::TABELA_MARCA, function (Blueprint $t) {
                $t->string('tabela', 64)->primary();
                $t->string('estado', 20); // EM_ANDAMENTO | CONVERTIDA | CONCLUIDA | BANCO_NOVO
                $t->unsignedBigInteger('linhas')->default(0);
                $t->json('gatilhos')->nullable(); // definições dos gatilhos BEFORE UPDATE removidos temporariamente
                $t->char('gatilhos_mac', 64)->nullable(); // HMAC (APP_KEY) da definição: retomada não executa DDL adulterado
                $t->json('detalhes')->nullable();
                $t->dateTime('atualizado_em', 6);
            });
        }
        if (DB::pretending()) {
            return;
        }
        app(ConversaoFusoService::class)->executar(ConversaoFusoService::bancoNovo());
    }

    public function down(): void
    {
        // Reverter o fuso exigiria somar 3 h de volta só nas linhas antigas: rollback = restaurar o backup pré-migração.
        $convertido = Schema::hasTable(ConversaoFusoService::TABELA_MARCA)
            && DB::table(ConversaoFusoService::TABELA_MARCA)->where('estado', '<>', 'BANCO_NOVO')->exists();
        if ($convertido) {
            throw new RuntimeException('SIGARB: conversão de fuso não é reversível por migração; restaure o backup feito antes de migrar.');
        }
        Schema::dropIfExists(ConversaoFusoService::TABELA_MARCA);
    }
};
