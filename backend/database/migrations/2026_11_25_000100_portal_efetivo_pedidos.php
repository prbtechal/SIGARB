<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Portal do efetivo (decisão do responsável, 2026-11-18): pedido prévio de material pelo próprio efetivo, aprovação antecipada
 * pelo armeiro e entrega presencial com conferência de identidade. Aditiva: tabelas novas, uma coluna nula em notificacoes,
 * papel EFETIVO e permissões novas. Nenhuma linha existente é reescrita; papéis já editados pela tela só GANHAM a permissão nova.
 *  - pedidos_material / pedido_material_itens: o pedido e suas linhas (quantidade pedida, aprovada e entregue).
 *  - pedido_material_acautelamentos: arma de fogo do pedido entregue por acautelamento (um por item).
 *  - notificacoes.pedido_material_id: notificação in-app ligada ao pedido.
 *  - permissões portal.efetivo (papel EFETIVO) e pedidos.gerir (ARMEIRO, GESTOR, APROVADOR).
 */
return new class extends Migration
{
    private const GERIR = ['ARMEIRO', 'GESTOR', 'APROVADOR'];

    public function up(): void
    {
        Schema::create('pedidos_material', function (Blueprint $t) {
            $t->id();
            $t->char('codigo', 26)->unique();
            $t->foreignId('militar_id')->constrained('militares')->restrictOnDelete();
            $t->foreignId('unidade_id')->constrained('unidades')->restrictOnDelete(); // unidade do efetivo no pedido (escopo do armeiro)
            $t->foreignId('solicitante_id')->constrained('usuarios')->restrictOnDelete();
            $t->string('status', 20)->default('PENDENTE');
            $t->date('retirada_prevista')->nullable();
            $t->foreignId('local_retirada_id')->nullable()->constrained('locais_estoque')->restrictOnDelete();
            $t->string('observacao', 500)->nullable();
            $t->foreignId('decidido_por_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $t->timestamp('decidido_em')->nullable();
            $t->string('motivo_recusa', 500)->nullable();
            $t->string('observacao_armeiro', 500)->nullable();
            $t->timestamp('cancelado_em')->nullable();
            $t->foreignId('entregue_por_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $t->timestamp('entregue_em')->nullable();
            $t->foreignId('entrega_movimentacao_id')->nullable()->constrained('movimentacoes')->restrictOnDelete();
            $t->string('entrega_chave', 80)->nullable()->unique();
            $t->unsignedInteger('versao')->default(1);
            $t->timestamps();
            $t->index(['militar_id', 'status']);
            $t->index(['unidade_id', 'status']);
        });

        Schema::create('pedido_material_itens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pedido_id')->constrained('pedidos_material')->restrictOnDelete();
            $t->foreignId('material_id')->constrained('materiais')->restrictOnDelete();
            $t->unsignedInteger('quantidade');
            $t->unsignedInteger('quantidade_aprovada')->nullable();
            $t->unsignedInteger('quantidade_entregue')->nullable();
            $t->timestamps();
            $t->unique(['pedido_id', 'material_id']);
        });

        Schema::create('pedido_material_acautelamentos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pedido_id')->constrained('pedidos_material')->restrictOnDelete();
            $t->foreignId('acautelamento_id')->unique()->constrained('acautelamentos')->restrictOnDelete();
        });

        Schema::table('notificacoes', function (Blueprint $t) {
            $t->foreignId('pedido_material_id')->nullable()->after('chamado_id')->constrained('pedidos_material')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE pedidos_material ADD CONSTRAINT chk_pedido_status CHECK (status IN ('PENDENTE','APROVADO','RECUSADO','CANCELADO','ENTREGUE'))");
        }

        $cfg = config('sigarb_permissoes');
        foreach (['portal.efetivo', 'pedidos.gerir'] as $codigo) {
            DB::table('permissoes')->insertOrIgnore(['codigo' => $codigo, 'descricao' => $cfg['permissoes'][$codigo]]);
        }
        $p = $cfg['papeis']['EFETIVO'];
        DB::table('papeis')->insertOrIgnore(['codigo' => 'EFETIVO', 'nome' => $p['nome'], 'descricao' => $p['descricao']]);
        $efetivo = DB::table('papeis')->where('codigo', 'EFETIVO')->value('id');
        foreach (DB::table('permissoes')->whereIn('codigo', $p['permissoes'])->pluck('id') as $perm) {
            DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $efetivo, 'permissao_id' => $perm]);
        }
        $gerir = DB::table('permissoes')->where('codigo', 'pedidos.gerir')->value('id');
        foreach (DB::table('papeis')->whereIn('codigo', self::GERIR)->pluck('id') as $papel) {
            DB::table('papel_permissao')->insertOrIgnore(['papel_id' => $papel, 'permissao_id' => $gerir]);
        }
    }

    public function down(): void
    {
        DB::transaction(function () {
            $papel = DB::table('papeis')->where('codigo', 'EFETIVO')->value('id');
            if ($papel && ! DB::table('atribuicoes_papel')->where('papel_id', $papel)->exists()) {
                DB::table('papel_permissao')->where('papel_id', $papel)->delete();
                DB::table('papeis')->where('id', $papel)->delete();
            }
            foreach (['portal.efetivo', 'pedidos.gerir'] as $codigo) {
                if ($perm = DB::table('permissoes')->where('codigo', $codigo)->value('id')) {
                    DB::table('papel_permissao')->where('permissao_id', $perm)->delete();
                    DB::table('permissoes')->where('id', $perm)->delete();
                }
            }
        });
        Schema::table('notificacoes', fn (Blueprint $t) => $t->dropConstrainedForeignId('pedido_material_id'));
        Schema::dropIfExists('pedido_material_acautelamentos');
        Schema::dropIfExists('pedido_material_itens');
        Schema::dropIfExists('pedidos_material');
    }
};
