<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CP-16: ajustes do sistema editáveis pelo Administrador. Linha com valor NULL = volta ao padrão do arquivo (runtime não tem DELETE).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracoes', function (Blueprint $t) {
            $t->string('chave', 80)->primary();
            $t->json('valor')->nullable();
            $t->string('motivo', 500);
            $t->foreignId('alterado_por')->constrained('usuarios');
            $t->timestamp('alterado_em');
        });
        DB::table('permissoes')->insertOrIgnore(['codigo' => 'sistema.configurar', 'descricao' => config('sigarb_permissoes.permissoes')['sistema.configurar']]);
        if ($admin = DB::table('papeis')->where('codigo', 'ADMIN')->value('id')) {
            DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $admin, 'permissao_id' => DB::table('permissoes')->where('codigo', 'sistema.configurar')->value('id')]);
        }
    }

    public function down(): void
    {
        DB::transaction(function () {
            $perm = DB::table('permissoes')->where('codigo', 'sistema.configurar')->value('id');
            if ($perm) {
                DB::table('papel_permissao')->where('permissao_id', $perm)->delete();
                DB::table('permissoes')->where('id', $perm)->delete();
            }
        });
        Schema::dropIfExists('configuracoes');
    }
};
