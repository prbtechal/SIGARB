<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// CP-14 (P-06, decisão do responsável): delegação/substituição = atribuição de papel com prazo. Vencida, deixa de valer sozinha.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('atribuicoes_papel', function (Blueprint $t) {
            $t->timestamp('expira_em')->nullable()->after('concedido_por_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('atribuicoes_papel', function (Blueprint $t) {
            $t->dropIndex(['expira_em']);
            $t->dropColumn('expira_em');
        });
    }
};
