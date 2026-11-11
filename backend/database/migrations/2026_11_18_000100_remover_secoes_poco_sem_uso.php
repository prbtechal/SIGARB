<?php

use App\Models\Unidade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove seções do Poço criadas em 2026_11_17_000300 que não guardam material bélico (decisão do responsável, 2026-11-11).
 * Mantidas: SMB, FROTA, P4, LOGISTICA e SOCIAL (Logística e Social têm rádios no inventário de 2026).
 *
 * Só apaga a seção sem NENHUMA referência: qualquer FK para unidades.id (locais, vínculos de efetivo, papéis, auditoria,
 * documentos, subunidades…) é conferida pelo esquema, não por lista fixa. Com referência, a seção é só desativada (ativo=false),
 * o mesmo mecanismo da tela, e nada referenciado é tocado. Idempotente: seção ausente é ignorada.
 */
return new class extends Migration
{
    public const SIGLAS = ['POCO-GERENCIA', 'POCO-COORDENACAO', 'POCO-P1', 'POCO-P3', 'POCO-RH', 'POCO-TI', 'POCO-TELE', 'POCO-DISC', 'POCO-BANDA'];

    public function up(): void
    {
        $refs = []; // [tabela, coluna] que apontam para unidades.id
        foreach (Schema::getTableListing(schemaQualified: false) as $t) {
            foreach (Schema::getForeignKeys($t) as $fk) {
                if ($fk['foreign_table'] === 'unidades') {
                    $refs[] = [$t, $fk['columns'][0]];
                }
            }
        }
        foreach (Unidade::whereIn('sigla', self::SIGLAS)->whereHas('organizacao', fn ($q) => $q->where('sigla', 'PRB'))->get() as $u) {
            $usada = collect($refs)->contains(fn ($r) => DB::table($r[0])->where($r[1], $u->id)->exists());
            $usada ? $u->forceFill(['ativo' => false])->saveQuietly() : $u->delete();
        }
    }

    public function down(): void
    {
        // Recria as seções apagadas (mesmos nomes da 2026_11_17_000300); as desativadas voltam a ativas.
        $poco = Unidade::where('sigla', 'MACEIO-POCO')->whereHas('organizacao', fn ($q) => $q->where('sigla', 'PRB'))->first();
        if (! $poco) {
            return;
        }
        $nomes = (require __DIR__.'/2026_11_17_000300_estrutura_prb_raiz_e_secoes.php')::SECOES_POCO;
        foreach (self::SIGLAS as $sigla) {
            Unidade::updateOrCreate(['organizacao_id' => $poco->organizacao_id, 'sigla' => $sigla],
                ['nome' => $nomes[substr($sigla, 5)], 'tipo' => 'SUBUNIDADE', 'unidade_superior_id' => $poco->id, 'ativo' => true]);
        }
    }
};
