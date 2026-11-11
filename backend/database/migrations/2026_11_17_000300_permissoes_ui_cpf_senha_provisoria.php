<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - `militares.classificar`: só quem tem pode definir o tipo de efetivo (que habilita ou não armamento). Começa só no ADMIN.
 * - `usuarios.senha_provisoria`: conta com senha padrão/redefinida precisa trocar a senha no primeiro acesso.
 */
return new class extends Migration
{
    public function up(): void
    {
        $id = DB::table('permissoes')->insertGetId(['codigo' => 'militares.classificar', 'descricao' => 'Definir o tipo de efetivo (RR, R1, vigilante, PM/BM da ativa), que habilita ou não armamento']);
        if ($admin = DB::table('papeis')->where('codigo', 'ADMIN')->value('id')) {
            DB::table('papel_permissao')->insert(['papel_id' => $admin, 'permissao_id' => $id]);
        }
        Schema::table('usuarios', fn (Blueprint $t) => $t->boolean('senha_provisoria')->default(false)->after('password'));
    }

    public function down(): void
    {
        Schema::table('usuarios', fn (Blueprint $t) => $t->dropColumn('senha_provisoria'));
        DB::table('permissoes')->where('codigo', 'militares.classificar')->delete();
    }
};
