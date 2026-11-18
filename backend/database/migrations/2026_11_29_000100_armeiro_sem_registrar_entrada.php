<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisão do responsável (2026-11-18): registrar entrada de material no estoque é da gestão.
 * Tira `estoque.entrada` do papel ARMEIRO (a matriz continua editável em Papéis e permissões).
 * A conservação do item segue liberada ao armeiro por `estoque.editar`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $papel = DB::table('papeis')->where('codigo', 'ARMEIRO')->value('id');
        $perm = DB::table('permissoes')->where('codigo', 'estoque.entrada')->value('id');
        if ($papel && $perm) {
            DB::table('papel_permissao')->where('papel_id', $papel)->where('permissao_id', $perm)->delete();
        }
    }

    public function down(): void
    {
        $papel = DB::table('papeis')->where('codigo', 'ARMEIRO')->value('id');
        $perm = DB::table('permissoes')->where('codigo', 'estoque.entrada')->value('id');
        if ($papel && $perm) {
            DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $papel, 'permissao_id' => $perm]);
        }
    }
};
