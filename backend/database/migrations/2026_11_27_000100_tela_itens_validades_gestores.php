<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisão do responsável (2026-11-18): a tela "Itens e validades" é só de gestores para cima.
 * Nova permissão de tela `estoque.itens`, concedida a GESTOR, AUDITOR e APROVADOR (se tiverem estoque.ver).
 * ARMEIRO e OPERADOR ficam sem a tela — a lista de itens usada na entrega/devolução continua por estoque.ver.
 * A matriz segue editável em Papéis e permissões (o administrador pode conceder depois).
 */
return new class extends Migration
{
    private const PAPEIS = ['GESTOR', 'AUDITOR', 'APROVADOR'];

    public function up(): void
    {
        DB::transaction(function () {
            DB::table('permissoes')->insertOrIgnore(['codigo' => 'estoque.itens', 'descricao' => config('sigarb_permissoes.permissoes')['estoque.itens']]);
            $perm = DB::table('permissoes')->where('codigo', 'estoque.itens')->value('id');
            $ver = DB::table('permissoes')->where('codigo', 'estoque.ver')->value('id');
            $papeis = DB::table('papeis')->whereIn('codigo', self::PAPEIS)->pluck('id');
            foreach (DB::table('papel_permissao')->where('permissao_id', $ver)->whereIn('papel_id', $papeis)->pluck('papel_id') as $papel) {
                DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $papel, 'permissao_id' => $perm]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $perm = DB::table('permissoes')->where('codigo', 'estoque.itens')->value('id');
            DB::table('papel_permissao')->where('permissao_id', $perm)->delete();
            DB::table('permissoes')->where('id', $perm)->delete();
        });
    }
};
