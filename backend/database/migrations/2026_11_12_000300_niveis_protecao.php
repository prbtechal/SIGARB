<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// CP-13 (P-04 aberta): níveis de proteção deixam de ser fixos no código e viram lista de referência administrável,
// para a instituição cadastrar a lista oficial (tela ou importação). Itens continuam guardando o nome (texto).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('niveis_protecao', function (Blueprint $t) {
            $t->id();
            $t->string('nome', 20)->unique();
            $t->string('descricao', 255)->nullable();
            $t->boolean('ativo')->default(true)->index();
            $t->timestamp('desativado_em')->nullable();
            $t->timestamps();
        });
        // Mesmos valores genéricos que estavam fixos no código + qualquer valor já gravado em item (nada fica órfão).
        $agora = now();
        $valores = collect(['IIA', 'II', 'IIIA', 'III', 'IV'])
            ->merge(DB::table('itens_individuais')->whereNotNull('nivel_protecao')->distinct()->pluck('nivel_protecao'))->unique();
        foreach ($valores as $v) {
            DB::table('niveis_protecao')->insertOrIgnore(['nome' => $v, 'ativo' => true, 'created_at' => $agora, 'updated_at' => $agora]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('niveis_protecao');
    }
};
