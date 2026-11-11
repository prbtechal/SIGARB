<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PSI-PRB v1.0 — cap. 10 (troca periódica, histórico das 5 últimas, bloqueio após 10 falhas) e cap. 11 (TOTP).
 * Contas existentes começam a contar os 90 dias a partir desta migração (não expiram todas no mesmo dia do deploy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $t) {
            $t->timestamp('senha_alterada_em')->nullable()->after('senha_provisoria');
            $t->unsignedSmallInteger('falhas_login')->default(0)->after('senha_alterada_em');
            $t->timestamp('bloqueado_em')->nullable()->after('falhas_login');
            $t->text('mfa_segredo')->nullable()->after('bloqueado_em'); // cifrado com APP_KEY (cast "encrypted")
            $t->timestamp('mfa_confirmado_em')->nullable()->after('mfa_segredo');
            $t->unsignedBigInteger('mfa_ultimo_passo')->nullable()->after('mfa_confirmado_em'); // impede reuso do mesmo código
            $t->json('mfa_recuperacao')->nullable()->after('mfa_ultimo_passo'); // SHA-256 dos códigos de recuperação não usados
        });
        DB::table('usuarios')->update(['senha_alterada_em' => now()]);

        Schema::create('historico_senhas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->constrained('usuarios')->restrictOnDelete();
            $t->string('password_hash');
            $t->timestamp('criado_em')->useCurrent();
            $t->index(['usuario_id', 'id']);
        });
        // A senha atual de cada conta entra como o primeiro item do histórico.
        DB::statement('INSERT INTO historico_senhas (usuario_id, password_hash, criado_em) SELECT id, password, NOW() FROM usuarios');
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_senhas');
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn(['senha_alterada_em', 'falhas_login', 'bloqueado_em', 'mfa_segredo', 'mfa_confirmado_em', 'mfa_ultimo_passo', 'mfa_recuperacao']));
    }
};
