<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisões do responsável (2026-11-18): o armeiro opera só a própria base e não acessa as telas de gestão.
 * Aditiva quanto a dados: nenhuma tabela nova e nenhuma movimentação tocada; só a matriz papel × permissão muda.
 *  - Permissões novas: movimentacoes.ver (tela Movimentações e Operações e pendências), inventario.ver (tela Inventários)
 *    e relatorios.entregas (relatório de entregas e devoluções). As duas primeiras vão para TODO papel que hoje tem
 *    estoque.ver, menos o ARMEIRO — quem já via essas telas continua vendo (inclusive papéis editados pela tela Permissões).
 *    relatorios.entregas vai para ARMEIRO e GESTOR.
 *  - ARMEIRO perde: militares.ver, organizacao.ver, catalogo.ver, catalogo.gerir, documentos.ver, documentos.enviar,
 *    inventario.contar, inventario.aprovar, dados.importar, relatorios.exportar. Os fluxos do armeiro passam a usar
 *    autorizações específicas (estoque.entregar/pedidos.gerir/estoque.ver) no escopo da base.
 * down(): devolve ao ARMEIRO o que foi tirado e apaga as três permissões novas (e suas concessões).
 */
return new class extends Migration
{
    private const NOVAS = ['movimentacoes.ver', 'inventario.ver', 'relatorios.entregas'];

    private const TIRAR_DO_ARMEIRO = ['militares.ver', 'organizacao.ver', 'catalogo.ver', 'catalogo.gerir', 'documentos.ver', 'documentos.enviar',
        'inventario.contar', 'inventario.aprovar', 'dados.importar', 'relatorios.exportar'];

    public function up(): void
    {
        DB::transaction(function () {
            $cfg = config('sigarb_permissoes.permissoes');
            foreach (self::NOVAS as $codigo) {
                DB::table('permissoes')->insertOrIgnore(['codigo' => $codigo, 'descricao' => $cfg[$codigo]]);
            }
            $id = fn (string $c) => DB::table('permissoes')->where('codigo', $c)->value('id');
            $armeiro = DB::table('papeis')->where('codigo', 'ARMEIRO')->value('id');

            $comEstoque = DB::table('papel_permissao')->where('permissao_id', $id('estoque.ver'))
                ->when($armeiro, fn ($q) => $q->where('papel_id', '<>', $armeiro))->pluck('papel_id');
            foreach (['movimentacoes.ver', 'inventario.ver'] as $c) {
                foreach ($comEstoque as $papel) {
                    DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $papel, 'permissao_id' => $id($c)]);
                }
            }
            foreach (DB::table('papeis')->whereIn('codigo', ['ARMEIRO', 'GESTOR'])->pluck('id') as $papel) {
                DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $papel, 'permissao_id' => $id('relatorios.entregas')]);
            }
            if ($armeiro) {
                DB::table('papel_permissao')->where('papel_id', $armeiro)
                    ->whereIn('permissao_id', DB::table('permissoes')->whereIn('codigo', self::TIRAR_DO_ARMEIRO)->select('id'))->delete();
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            if ($armeiro = DB::table('papeis')->where('codigo', 'ARMEIRO')->value('id')) {
                foreach (DB::table('permissoes')->whereIn('codigo', self::TIRAR_DO_ARMEIRO)->pluck('id') as $perm) {
                    DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $armeiro, 'permissao_id' => $perm]);
                }
            }
            $novas = DB::table('permissoes')->whereIn('codigo', self::NOVAS)->pluck('id');
            DB::table('papel_permissao')->whereIn('permissao_id', $novas)->delete();
            DB::table('permissoes')->whereIn('id', $novas)->delete();
        });
    }
};
