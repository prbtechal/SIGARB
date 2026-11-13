<?php

use App\Models\PostoGraduacao;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Efetivo alimentado pelo cadastro de funcionários (banco `funcionarios`, mesmo do Sistema de Escalas).
 * - militares.funcionario_id: id de origem (null = cadastro local).
 * - militares.cpf: liga a conta (login = CPF) ao efetivo.
 * - Posto "CIV" (Civil / sem posto): o cadastro de funcionários não tem posto/graduação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('militares', function (Blueprint $t) {
            $t->unsignedBigInteger('funcionario_id')->nullable()->unique()->after('id');
            $t->char('cpf', 11)->nullable()->unique()->after('matricula');
        });
        PostoGraduacao::firstOrCreate(['sigla' => 'CIV'], ['nome' => 'Civil / sem posto', 'ordem' => 0]);
    }

    public function down(): void
    {
        Schema::table('militares', fn (Blueprint $t) => $t->dropColumn(['funcionario_id', 'cpf']));
    }
};
