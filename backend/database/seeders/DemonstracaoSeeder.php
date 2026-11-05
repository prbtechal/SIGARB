<?php

namespace Database\Seeders;

use App\Models\AtribuicaoPapel;
use App\Models\Calibre;
use App\Models\Categoria;
use App\Models\Fabricante;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Organizacao;
use App\Models\Papel;
use App\Models\PostoGraduacao;
use App\Models\Unidade;
use App\Models\Usuario;
use App\Services\EstoqueService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DADOS 100% FICTÍCIOS para demonstração local. Nenhum nome, matrícula, unidade ou material corresponde a registro real.
 * A senha dos usuários de demonstração vem de SIGARB_DEMO_PASSWORD (nunca do código-fonte).
 */
class DemonstracaoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemonstracaoSeeder é proibido em produção.');
        }
        $senha = env('SIGARB_DEMO_PASSWORD');
        if (! $senha || strlen($senha) < 12) {
            throw new RuntimeException('Defina SIGARB_DEMO_PASSWORD (>= 12 caracteres) no .env local antes de semear a demonstração.');
        }

        $org = Organizacao::firstOrCreate(['sigla' => 'ORG-DEMO'], ['nome' => 'Organização Fictícia de Demonstração']);
        $alfa = Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => 'UN-ALFA'], ['nome' => 'Unidade Alfa (fictícia)', 'tipo' => 'UNIDADE']);
        $alfa1 = Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => 'SU-ALFA-1'], ['nome' => 'Subunidade Alfa-1 (fictícia)', 'tipo' => 'SUBUNIDADE', 'unidade_superior_id' => $alfa->id]);
        $bravo = Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => 'UN-BRAVO'], ['nome' => 'Unidade Bravo (fictícia)', 'tipo' => 'UNIDADE']);

        $resAlfa = LocalEstoque::firstOrCreate(['codigo' => 'RES-ALFA-01'], ['unidade_id' => $alfa->id, 'nome' => 'Reserva Alfa 01 (fictícia)', 'tipo' => 'RESERVA']);
        $depAlfa1 = LocalEstoque::firstOrCreate(['codigo' => 'DEP-ALFA1-01'], ['unidade_id' => $alfa1->id, 'nome' => 'Depósito Alfa-1 (fictício)', 'tipo' => 'DEPOSITO']);
        $resBravo = LocalEstoque::firstOrCreate(['codigo' => 'RES-BRAVO-01'], ['unidade_id' => $bravo->id, 'nome' => 'Reserva Bravo 01 (fictícia)', 'tipo' => 'RESERVA']);

        $usuarios = [
            ['admin.demo', 'Administrador Demonstração', 'ADMIN', null],
            ['gestor.alfa', 'Gestora Alfa Demonstração', 'GESTOR', $alfa->id],
            ['operador.alfa1', 'Operador Alfa-1 Demonstração', 'OPERADOR', $alfa1->id],
            ['gestor.bravo', 'Gestor Bravo Demonstração', 'GESTOR', $bravo->id],
            ['auditor.demo', 'Auditora Demonstração', 'AUDITOR', null],
            ['aprovador.demo', 'Aprovador Demonstração', 'APROVADOR', $alfa->id],
        ];
        $criados = [];
        foreach ($usuarios as [$login, $nome, $papel, $unidade]) {
            $u = Usuario::firstOrCreate(['login' => $login], ['nome' => $nome, 'password' => $senha]);
            AtribuicaoPapel::firstOrCreate(['usuario_id' => $u->id, 'papel_id' => Papel::where('codigo', $papel)->value('id'), 'unidade_id' => $unidade]);
            $criados[$login] = $u;
        }

        $postos = PostoGraduacao::pluck('id', 'sigla');
        $pessoas = [
            ['Ana Fictícia Rocha', 'ROCHA', 'DEMO-0001', '3SGT', $alfa], ['Bruno Exemplo Lima', 'EXEMPLO', 'DEMO-0002', 'CB', $alfa],
            ['Carla Modelo Dias', 'MODELO', 'DEMO-0003', 'SD', $alfa1], ['Diego Teste Nunes', 'TESTE', 'DEMO-0004', '1TEN', $alfa1],
            ['Elisa Amostra Pires', 'AMOSTRA', 'DEMO-0005', 'CAP', $bravo], ['Fábio Simulado Reis', 'SIMULADO', 'DEMO-0006', '2SGT', $bravo],
        ];
        foreach ($pessoas as [$nome, $guerra, $mat, $posto, $un]) {
            Militar::firstOrCreate(['matricula' => $mat], ['nome' => $nome, 'nome_guerra' => $guerra, 'posto_graduacao_id' => $postos[$posto], 'unidade_id' => $un->id]);
        }
        $criados['operador.alfa1']->update(['militar_id' => Militar::where('matricula', 'DEMO-0004')->value('id')]);

        $catArm = Categoria::firstOrCreate(['codigo' => 'ARM-DEMO'], ['nome' => 'Armamento (demonstração)', 'natureza' => 'ARMAMENTO']);
        $catMun = Categoria::firstOrCreate(['codigo' => 'MUN-DEMO'], ['nome' => 'Munição (demonstração)', 'natureza' => 'MUNICAO']);
        $catEqp = Categoria::firstOrCreate(['codigo' => 'EQP-DEMO'], ['nome' => 'Equipamento (demonstração)', 'natureza' => 'EQUIPAMENTO']);
        $fab = Fabricante::firstOrCreate(['nome' => 'Fabricante Fictício S.A.']);
        $cal = Calibre::firstOrCreate(['nome' => 'CAL-DEMO']);

        $pistola = Material::firstOrCreate(['codigo' => 'DEMO-PST-01'], ['categoria_id' => $catArm->id, 'fabricante_id' => $fab->id, 'calibre_id' => $cal->id, 'nome' => 'Pistola de demonstração P1', 'modelo' => 'P1-DEMO', 'modo_controle' => 'INDIVIDUAL']);
        $cartucho = Material::firstOrCreate(['codigo' => 'DEMO-MUN-01'], ['categoria_id' => $catMun->id, 'fabricante_id' => $fab->id, 'calibre_id' => $cal->id, 'nome' => 'Cartucho de demonstração', 'modelo' => 'C-DEMO', 'modo_controle' => 'LOTE']);
        $colete = Material::firstOrCreate(['codigo' => 'DEMO-EQP-01'], ['categoria_id' => $catEqp->id, 'fabricante_id' => $fab->id, 'nome' => 'Colete de demonstração', 'modelo' => 'CL-DEMO', 'modo_controle' => 'INDIVIDUAL']);
        Material::firstOrCreate(['codigo' => 'DEMO-PRD-01'], ['categoria_id' => $catEqp->id, 'nome' => 'Bateria de demonstração', 'modo_controle' => 'LOTE']);

        if (\App\Models\Movimentacao::exists()) {
            return; // estoque de demonstração já semeado
        }
        $svc = app(EstoqueService::class);
        auth()->setUser($criados['gestor.alfa']);
        $svc->registrarEntrada($criados['gestor.alfa'], [
            'local_destino_id' => $resAlfa->id, 'origem_descricao' => 'Carga inicial fictícia de demonstração', 'documento_referencia' => 'DOC-DEMO-0001',
            'itens' => [
                ['material_id' => $pistola->id, 'identificador' => 'PAT-DEMO-0001', 'numero_serie' => 'SER-DEMO-A1'],
                ['material_id' => $pistola->id, 'identificador' => 'PAT-DEMO-0002', 'numero_serie' => 'SER-DEMO-A2'],
                ['material_id' => $colete->id, 'identificador' => 'PAT-DEMO-0101'],
                ['material_id' => $cartucho->id, 'lote_codigo' => 'LOTE-DEMO-01', 'validade' => now()->addDays(60)->toDateString(), 'quantidade' => 500],
            ],
        ], (string) Str::uuid());
        $svc->registrarEntrada($criados['gestor.alfa'], [
            'local_destino_id' => $depAlfa1->id, 'origem_descricao' => 'Carga inicial fictícia de demonstração', 'documento_referencia' => 'DOC-DEMO-0002',
            'itens' => [['material_id' => $cartucho->id, 'lote_codigo' => 'LOTE-DEMO-02', 'validade' => now()->addYears(2)->toDateString(), 'quantidade' => 1200]],
        ], (string) Str::uuid());
        auth()->setUser($criados['gestor.bravo']);
        $svc->registrarEntrada($criados['gestor.bravo'], [
            'local_destino_id' => $resBravo->id, 'origem_descricao' => 'Carga inicial fictícia de demonstração', 'documento_referencia' => 'DOC-DEMO-0003',
            'itens' => [['material_id' => $pistola->id, 'identificador' => 'PAT-DEMO-0201', 'numero_serie' => 'SER-DEMO-B1']],
        ], (string) Str::uuid());
    }
}
