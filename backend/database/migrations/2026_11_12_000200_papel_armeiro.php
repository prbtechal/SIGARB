<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// CP-13: papel ARMEIRO e permissão dados.importar em bancos já existentes (o deploy não roda seeders).
// Só insere o que falta; não reescreve nomes nem permissões de papéis já editados.
return new class extends Migration
{
    public function up(): void
    {
        $cfg = config('sigarb_permissoes');
        DB::table('permissoes')->insertOrIgnore(['codigo' => 'dados.importar', 'descricao' => $cfg['permissoes']['dados.importar']]);
        $p = $cfg['papeis']['ARMEIRO'];
        DB::table('papeis')->insertOrIgnore(['codigo' => 'ARMEIRO', 'nome' => $p['nome'], 'descricao' => $p['descricao']]);
        $papel = DB::table('papeis')->where('codigo', 'ARMEIRO')->value('id');
        foreach (DB::table('permissoes')->whereIn('codigo', $p['permissoes'])->pluck('id') as $perm) {
            DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $papel, 'permissao_id' => $perm]);
        }
        foreach (['ADMIN', 'GESTOR'] as $codigo) {
            if ($id = DB::table('papeis')->where('codigo', $codigo)->value('id')) {
                DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $id, 'permissao_id' => DB::table('permissoes')->where('codigo', 'dados.importar')->value('id')]);
            }
        }
    }

    public function down(): void
    {
        $papel = DB::table('papeis')->where('codigo', 'ARMEIRO')->value('id');
        if ($papel && ! DB::table('atribuicoes_papel')->where('papel_id', $papel)->exists()) {
            DB::table('papel_permissao')->where('papel_id', $papel)->delete();
            DB::table('papeis')->whereKey($papel)->delete();
        }
        $perm = DB::table('permissoes')->where('codigo', 'dados.importar')->value('id');
        if ($perm) {
            DB::table('papel_permissao')->where('permissao_id', $perm)->delete();
            DB::table('permissoes')->whereKey($perm)->delete();
        }
    }
};
