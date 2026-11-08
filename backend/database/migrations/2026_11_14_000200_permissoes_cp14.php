<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// CP-14: Armeiro passa a fazer baixa, saída, transferência e aprovações; Gestor passa a aprovar. Também em bancos implantados.
return new class extends Migration
{
    private const NOVAS = [
        'ARMEIRO' => ['estoque.saida', 'estoque.baixar', 'estoque.transferir', 'acautelamento.solicitar', 'acautelamento.aprovar', 'inventario.aprovar'],
        'GESTOR' => ['acautelamento.aprovar', 'inventario.aprovar'],
    ];

    public function up(): void
    {
        foreach (self::NOVAS as $papel => $perms) {
            $p = DB::table('papeis')->where('codigo', $papel)->value('id');
            foreach ($p ? DB::table('permissoes')->whereIn('codigo', $perms)->pluck('id') : [] as $perm) {
                DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $p, 'permissao_id' => $perm]);
            }
        }
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::NOVAS as $papel => $perms) {
                DB::table('papel_permissao')->where('papel_id', DB::table('papeis')->where('codigo', $papel)->value('id'))
                    ->whereIn('permissao_id', DB::table('permissoes')->whereIn('codigo', $perms)->pluck('id'))->delete();
            }
        });
    }
};
