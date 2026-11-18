<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisão do responsável (2026-11-18): locais do tipo DEPÓSITO (ex.: DEP-SMB) são da gestão — retomada de estoque,
 * conferência (auditoria) e distribuição entre unidades. O armeiro trabalha só com a reserva da base.
 * Nova permissão `estoque.depositos`, concedida a ADMIN, GESTOR, AUDITOR e APROVADOR ("gestores para cima").
 * ARMEIRO e OPERADOR ficam sem ela; a matriz segue editável em Papéis e permissões.
 */
return new class extends Migration
{
    private const PAPEIS = ['ADMIN', 'GESTOR', 'AUDITOR', 'APROVADOR'];

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('permissoes')->insertOrIgnore(['codigo' => 'estoque.depositos', 'descricao' => config('sigarb_permissoes.permissoes')['estoque.depositos']]);
            $perm = DB::table('permissoes')->where('codigo', 'estoque.depositos')->value('id');
            foreach (DB::table('papeis')->whereIn('codigo', self::PAPEIS)->pluck('id') as $papel) {
                DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $papel, 'permissao_id' => $perm]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $perm = DB::table('permissoes')->where('codigo', 'estoque.depositos')->value('id');
            DB::table('papel_permissao')->where('permissao_id', $perm)->delete();
            DB::table('permissoes')->where('id', $perm)->delete();
        });
    }
};
