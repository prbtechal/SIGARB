<?php

use App\Services\AuditoriaService;
use App\Support\CatalogoOficial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identificador do item individual gerado pelo sistema: <prefixo da categoria>-<sequência de 6 dígitos>, ex.: ARM-000001.
 * Sequência por prefixo, global (não depende de local/unidade: o item muda de lugar sem trocar de código).
 *
 * - categorias.prefixo_codigo (3 letras; categoria sem prefixo usa "ITM") e tabela sequencias_identificador
 *   (prefixo => último número; o EstoqueService trava a linha com FOR UPDATE na transação da entrada).
 * - Itens existentes (identificador antigo = prefixo + série, ex.: ARM-SXH-15937) recebem o código novo na ordem do id;
 *   numero_serie não muda. O razão (movimentacao_itens) referencia o item só pelo id, então nada imutável é reescrito.
 *   Eventos antigos de auditoria podem citar o código antigo: cada troca gera o evento item.identificador_regenerado
 *   (antigo → novo), que é o mapa de rastreabilidade.
 * - Idempotente: a regeneração só roda enquanto não houver sequência emitida.
 * - down(): remove só a coluna e a tabela; os códigos novos ficam (o mapa antigo → novo está na auditoria).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categorias', 'prefixo_codigo')) {
            Schema::table('categorias', fn (Blueprint $t) => $t->string('prefixo_codigo', 3)->nullable()->unique()->after('codigo'));
        }
        if (! Schema::hasTable('sequencias_identificador')) {
            Schema::create('sequencias_identificador', function (Blueprint $t) {
                $t->string('prefixo', 3)->primary();
                $t->unsignedInteger('ultimo')->default(0);
            });
        }
        foreach (CatalogoOficial::CATEGORIAS as $codigo => [, , $prefixo]) {
            DB::table('categorias')->where('codigo', $codigo)->whereNull('prefixo_codigo')->update(['prefixo_codigo' => $prefixo]);
        }
        if (DB::table('sequencias_identificador')->exists()) {
            return;
        }

        $aud = app(AuditoriaService::class);
        DB::transaction(function () use ($aud) {
            $itens = DB::table('itens_individuais as i')->join('materiais as m', 'm.id', '=', 'i.material_id')->join('categorias as c', 'c.id', '=', 'm.categoria_id')
                ->orderBy('i.id')->get(['i.id', 'i.identificador', 'i.numero_serie', 'c.prefixo_codigo']);
            // Duas fases: um código novo pode coincidir com um antigo de outro item (ex.: série "000001").
            foreach ($itens as $i) {
                DB::table('itens_individuais')->where('id', $i->id)->update(['identificador' => "TMP-{$i->id}"]);
            }
            $seq = [];
            foreach ($itens as $i) {
                $p = $i->prefixo_codigo ?? 'ITM';
                $novo = sprintf('%s-%06d', $p, $seq[$p] = ($seq[$p] ?? 0) + 1);
                DB::table('itens_individuais')->where('id', $i->id)->update(['identificador' => $novo]);
                $aud->registrar('item.identificador_regenerado', 'SUCESSO', 'item_individual', $i->id,
                    dados: ['antigo' => $i->identificador, 'novo' => $novo, 'numero_serie' => $i->numero_serie]);
            }
            foreach ($seq as $p => $n) {
                DB::table('sequencias_identificador')->insert(['prefixo' => $p, 'ultimo' => $n]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequencias_identificador');
        if (Schema::hasColumn('categorias', 'prefixo_codigo')) {
            Schema::table('categorias', function (Blueprint $t) {
                $t->dropUnique(['prefixo_codigo']);
                $t->dropColumn('prefixo_codigo');
            });
        }
    }
};
