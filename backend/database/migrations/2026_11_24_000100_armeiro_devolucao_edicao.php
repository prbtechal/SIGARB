<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisões do responsável (2026-11-16) — fluxo do armeiro: entrega, devolução, baixa, edição e ajuste.
 * Aditiva (produção tem itens e movimentações reais): colunas novas e nulas; nenhuma linha existente é reescrita.
 *  - movimentacoes.devolvido_por_militar_id / devolvido_por_nome: quem trouxe o material de volta (efetivo ou terceiro sem cadastro).
 *  - movimentacao_itens.estado_conservacao / estado_anterior: estado de cada item individual na devolução (e o que era antes).
 *  - permissão estoque.editar (corrigir dados do item/lote e ajustar quantidade de lote), concedida a ARMEIRO, GESTOR e ADMIN.
 * O razão segue imutável: ALTER TABLE não dispara os triggers de UPDATE/DELETE por linha.
 */
return new class extends Migration
{
    private const PAPEIS = ['ARMEIRO', 'GESTOR', 'ADMIN'];

    public function up(): void
    {
        Schema::table('movimentacoes', function (Blueprint $t) {
            $t->foreignId('devolvido_por_militar_id')->nullable()->after('militar_id')->constrained('militares')->restrictOnDelete();
            $t->string('devolvido_por_nome', 120)->nullable()->after('devolvido_por_militar_id');
        });
        Schema::table('movimentacao_itens', function (Blueprint $t) {
            $t->string('estado_conservacao', 30)->nullable()->after('quantidade');
            $t->string('estado_anterior', 30)->nullable()->after('estado_conservacao');
        });

        DB::table('permissoes')->insertOrIgnore(['codigo' => 'estoque.editar', 'descricao' => config('sigarb_permissoes.permissoes')['estoque.editar']]);
        $perm = DB::table('permissoes')->where('codigo', 'estoque.editar')->value('id');
        foreach (DB::table('papeis')->whereIn('codigo', self::PAPEIS)->pluck('id') as $papel) {
            DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $papel, 'permissao_id' => $perm]);
        }
    }

    public function down(): void
    {
        DB::transaction(function () {
            $perm = DB::table('permissoes')->where('codigo', 'estoque.editar')->value('id');
            if ($perm) {
                DB::table('papel_permissao')->where('permissao_id', $perm)->delete();
                DB::table('permissoes')->where('id', $perm)->delete();
            }
        });
        Schema::table('movimentacao_itens', fn (Blueprint $t) => $t->dropColumn(['estado_conservacao', 'estado_anterior']));
        Schema::table('movimentacoes', fn (Blueprint $t) => $t->dropConstrainedForeignId('devolvido_por_militar_id'));
        Schema::table('movimentacoes', fn (Blueprint $t) => $t->dropColumn('devolvido_por_nome'));
    }
};
