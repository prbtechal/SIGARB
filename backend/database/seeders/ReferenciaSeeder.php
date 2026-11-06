<?php

namespace Database\Seeders;

use App\Models\Papel;
use App\Models\Permissao;
use App\Models\PostoGraduacao;
use Illuminate\Database\Seeder;

/** Dados de referência (papéis/permissões propostos e postos genéricos). Idempotente. */
class ReferenciaSeeder extends Seeder
{
    public function run(): void
    {
        $cfg = config('sigarb_permissoes');
        foreach ($cfg['permissoes'] as $codigo => $descricao) {
            Permissao::updateOrCreate(['codigo' => $codigo], ['descricao' => $descricao]);
        }
        foreach ($cfg['papeis'] as $codigo => $p) {
            $papel = Papel::updateOrCreate(['codigo' => $codigo], ['nome' => $p['nome'], 'descricao' => $p['descricao']]);
            $papel->permissoes()->sync(Permissao::whereIn('codigo', $p['permissoes'])->pluck('id'));
        }
        // Tipos de documento GENÉRICOS e provisórios (lista oficial pendente; editável pelo catálogo).
        foreach ([['NOTA_RECEBIMENTO', 'Nota de recebimento'], ['TERMO', 'Termo'], ['OFICIO', 'Ofício'], ['RELATORIO', 'Relatório'], ['FOTO', 'Registro fotográfico'], ['OUTRO', 'Outro']] as [$c, $n]) {
            \App\Models\TipoDocumento::updateOrCreate(['codigo' => $c], ['nome' => $n]);
        }
        // Lista genérica e provisória; a tabela oficial depende da instituição.
        foreach ([['SD', 'Soldado'], ['CB', 'Cabo'], ['3SGT', '3º Sargento'], ['2SGT', '2º Sargento'], ['1SGT', '1º Sargento'], ['ST', 'Subtenente'],
            ['2TEN', '2º Tenente'], ['1TEN', '1º Tenente'], ['CAP', 'Capitão'], ['MAJ', 'Major'], ['TC', 'Tenente-Coronel'], ['CEL', 'Coronel']] as $i => [$s, $n]) {
            PostoGraduacao::updateOrCreate(['sigla' => $s], ['nome' => $n, 'ordem' => $i + 1]);
        }
    }
}
