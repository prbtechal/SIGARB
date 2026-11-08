<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP-12 — atributos do item físico (ex.: colete: fabricação, validade, tamanho, nível de proteção). Colunas aditivas e nulas;
 * itens existentes não mudam. estado_conservacao segue string(30): a lista ampliada é validada na aplicação (App\Support\Conservacao).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itens_individuais', function (Blueprint $t) {
            $t->date('fabricado_em')->nullable()->after('estado_conservacao');
            $t->date('validade')->nullable()->after('fabricado_em')->index();
            $t->string('tamanho', 20)->nullable()->after('validade');
            $t->string('nivel_protecao', 10)->nullable()->after('tamanho');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE itens_individuais ADD CONSTRAINT chk_item_validade CHECK (validade IS NULL OR fabricado_em IS NULL OR validade >= fabricado_em)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE itens_individuais DROP CHECK chk_item_validade');
        }
        Schema::table('itens_individuais', fn (Blueprint $t) => $t->dropColumn(['fabricado_em', 'validade', 'tamanho', 'nivel_protecao']));
    }
};
