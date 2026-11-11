<?php

namespace Database\Seeders;

use App\Models\NivelProtecao;
use App\Models\Papel;
use App\Models\Permissao;
use App\Models\PostoGraduacao;
use App\Models\TipoDocumento;
use Illuminate\Database\Seeder;

/** Dados de referência (papéis/permissões propostos e postos genéricos). Idempotente. */
class ReferenciaSeeder extends Seeder
{
    public function run(): void
    {
        $cfg = config('sigarb_permissoes');
        $novas = [];
        foreach ($cfg['permissoes'] as $codigo => $descricao) {
            if (Permissao::updateOrCreate(['codigo' => $codigo], ['descricao' => $descricao])->wasRecentlyCreated) {
                $novas[] = $codigo;
            }
        }
        foreach ($cfg['papeis'] as $codigo => $p) {
            $papel = Papel::updateOrCreate(['codigo' => $codigo], ['nome' => $p['nome'], 'descricao' => $p['descricao']]);
            // A matriz do arquivo só preenche papel novo/vazio e permissão nova: o resto é editado pela tela e não é sobrescrito.
            $codigos = $papel->wasRecentlyCreated || ! $papel->permissoes()->exists() ? $p['permissoes'] : array_intersect($p['permissoes'], $novas);
            $papel->permissoes()->syncWithoutDetaching(Permissao::whereIn('codigo', $codigos)->pluck('id'));
        }
        // Tipos de documento GENÉRICOS e provisórios (lista oficial pendente; editável pelo catálogo).
        foreach ([['NOTA_RECEBIMENTO', 'Nota de recebimento'], ['TERMO', 'Termo'], ['OFICIO', 'Ofício'], ['RELATORIO', 'Relatório'], ['FOTO', 'Registro fotográfico'], ['OUTRO', 'Outro']] as [$c, $n]) {
            TipoDocumento::updateOrCreate(['codigo' => $c], ['nome' => $n]);
        }
        // Níveis de proteção genéricos (lista oficial: P-04). firstOrCreate não reescreve nem reativa o que a instituição editou.
        foreach (['IIA', 'II', 'IIIA', 'III', 'IV'] as $n) {
            NivelProtecao::firstOrCreate(['nome' => $n]);
        }
        // Lista genérica e provisória; a tabela oficial depende da instituição.
        foreach ([['SD', 'Soldado'], ['CB', 'Cabo'], ['3SGT', '3º Sargento'], ['2SGT', '2º Sargento'], ['1SGT', '1º Sargento'], ['ST', 'Subtenente'],
            ['2TEN', '2º Tenente'], ['1TEN', '1º Tenente'], ['CAP', 'Capitão'], ['MAJ', 'Major'], ['TC', 'Tenente-Coronel'], ['CEL', 'Coronel']] as $i => [$s, $n]) {
            PostoGraduacao::updateOrCreate(['sigla' => $s], ['nome' => $n, 'ordem' => $i + 1]);
        }
    }
}
