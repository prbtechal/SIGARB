<?php

namespace Database\Seeders;

use App\Models\AtribuicaoPapel;
use App\Models\Calibre;
use App\Models\Categoria;
use App\Models\Fabricante;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Militar;
use App\Models\Movimentacao;
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

        $org = Organizacao::firstOrCreate(['sigla' => 'DLA'], ['nome' => 'Diretoria de Logística e Armamento']);
        $alfa = Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => '1CIA'], ['nome' => '1ª Companhia', 'tipo' => 'UNIDADE']);
        $alfa1 = Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => '1PEL-1CIA'], ['nome' => '1º Pelotão da 1ª Companhia', 'tipo' => 'SUBUNIDADE', 'unidade_superior_id' => $alfa->id]);
        $bravo = Unidade::firstOrCreate(['organizacao_id' => $org->id, 'sigla' => '2CIA'], ['nome' => '2ª Companhia', 'tipo' => 'UNIDADE']);

        $resAlfa = LocalEstoque::firstOrCreate(['codigo' => 'RES-1CIA'], ['unidade_id' => $alfa->id, 'nome' => 'Reserva de Armamento da 1ª Cia', 'tipo' => 'RESERVA']);
        $depAlfa1 = LocalEstoque::firstOrCreate(['codigo' => 'DEP-1PEL'], ['unidade_id' => $alfa1->id, 'nome' => 'Depósito do 1º Pelotão', 'tipo' => 'DEPOSITO']);
        $resBravo = LocalEstoque::firstOrCreate(['codigo' => 'RES-2CIA'], ['unidade_id' => $bravo->id, 'nome' => 'Reserva de Armamento da 2ª Cia', 'tipo' => 'RESERVA']);

        $usuarios = [
            ['admin.demo', 'Ricardo Albuquerque', 'ADMIN', null],
            ['gestor.alfa', 'Mariana Tavares', 'GESTOR', $alfa->id],
            ['armeiro.alfa', 'Sérgio Nogueira', 'ARMEIRO', $alfa->id],
            ['operador.alfa1', 'Lucas Ferreira', 'OPERADOR', $alfa1->id],
            ['gestor.bravo', 'Paulo Henrique Costa', 'GESTOR', $bravo->id],
            ['auditor.demo', 'Juliana Moura', 'AUDITOR', null],
            ['aprovador.demo', 'Roberto Lins', 'APROVADOR', $alfa->id],
        ];
        $criados = [];
        foreach ($usuarios as [$login, $nome, $papel, $unidade]) {
            $u = Usuario::firstOrCreate(['login' => $login], ['nome' => $nome, 'password' => $senha]);
            AtribuicaoPapel::firstOrCreate(['usuario_id' => $u->id, 'papel_id' => Papel::where('codigo', $papel)->value('id'), 'unidade_id' => $unidade]);
            $criados[$login] = $u;
        }

        $postos = PostoGraduacao::pluck('id', 'sigla');
        $pessoas = [
            ['Ana Paula Rocha', 'ROCHA', '104512-3', '3SGT', $alfa], ['Bruno César Lima', 'LIMA', '104877-1', 'CB', $alfa],
            ['Carla Regina Dias', 'DIAS', '105203-6', 'SD', $alfa1], ['Diego Henrique Nunes', 'NUNES', '098341-2', '1TEN', $alfa1],
            ['Elisa Maria Pires', 'PIRES', '097115-8', 'CAP', $bravo], ['Fábio Augusto Reis', 'REIS', '103664-9', '2SGT', $bravo],
        ];
        foreach ($pessoas as [$nome, $guerra, $mat, $posto, $un]) {
            Militar::firstOrCreate(['matricula' => $mat], ['nome' => $nome, 'nome_guerra' => $guerra, 'posto_graduacao_id' => $postos[$posto], 'unidade_id' => $un->id]);
        }
        $criados['operador.alfa1']->update(['militar_id' => Militar::where('matricula', '098341-2')->value('id')]);

        $catArm = Categoria::firstOrCreate(['codigo' => 'ARM'], ['nome' => 'Armamento', 'natureza' => 'ARMAMENTO']);
        $catMun = Categoria::firstOrCreate(['codigo' => 'MUN'], ['nome' => 'Munição', 'natureza' => 'MUNICAO']);
        $catEqp = Categoria::firstOrCreate(['codigo' => 'EQP'], ['nome' => 'Equipamento de proteção e comunicação', 'natureza' => 'EQUIPAMENTO']);
        $fab = Fabricante::firstOrCreate(['nome' => 'Indústria Nacional de Defesa']);
        $cal = Calibre::firstOrCreate(['nome' => '9 mm']);

        $pistola = Material::firstOrCreate(['codigo' => 'PST-9MM'], ['categoria_id' => $catArm->id, 'fabricante_id' => $fab->id, 'calibre_id' => $cal->id, 'nome' => 'Pistola semiautomática 9 mm', 'modelo' => 'PS-9', 'modo_controle' => 'INDIVIDUAL']);
        $cartucho = Material::firstOrCreate(['codigo' => 'MUN-9MM'], ['categoria_id' => $catMun->id, 'fabricante_id' => $fab->id, 'calibre_id' => $cal->id, 'nome' => 'Cartucho 9 mm', 'modelo' => 'ETOG 124gr', 'modo_controle' => 'LOTE']);
        $colete = Material::firstOrCreate(['codigo' => 'COL-3A'], ['categoria_id' => $catEqp->id, 'fabricante_id' => $fab->id, 'nome' => 'Colete balístico nível III-A', 'modelo' => 'CB-3A', 'modo_controle' => 'INDIVIDUAL']);
        Material::firstOrCreate(['codigo' => 'BAT-RAD'], ['categoria_id' => $catEqp->id, 'nome' => 'Bateria para rádio portátil', 'modo_controle' => 'LOTE']);

        if (Movimentacao::exists()) {
            return; // estoque de demonstração já semeado
        }
        $svc = app(EstoqueService::class);
        auth()->setUser($criados['gestor.alfa']);
        $svc->registrarEntrada($criados['gestor.alfa'], [
            'local_destino_id' => $resAlfa->id, 'origem_descricao' => 'Carga inicial da reserva', 'documento_referencia' => 'NF-2026-0001',
            'itens' => [
                ['material_id' => $pistola->id, 'identificador' => 'PAT-0001', 'numero_serie' => 'SN-A1'],
                ['material_id' => $pistola->id, 'identificador' => 'PAT-0002', 'numero_serie' => 'SN-A2'],
                ['material_id' => $colete->id, 'identificador' => 'PAT-0101'],
                ['material_id' => $cartucho->id, 'lote_codigo' => 'LT-2026-01', 'validade' => now()->addDays(60)->toDateString(), 'quantidade' => 500],
            ],
        ], (string) Str::uuid());
        $svc->registrarEntrada($criados['gestor.alfa'], [
            'local_destino_id' => $depAlfa1->id, 'origem_descricao' => 'Carga inicial da reserva', 'documento_referencia' => 'NF-2026-0002',
            'itens' => [['material_id' => $cartucho->id, 'lote_codigo' => 'LT-2026-02', 'validade' => now()->addYears(2)->toDateString(), 'quantidade' => 1200]],
        ], (string) Str::uuid());
        auth()->setUser($criados['gestor.bravo']);
        $svc->registrarEntrada($criados['gestor.bravo'], [
            'local_destino_id' => $resBravo->id, 'origem_descricao' => 'Carga inicial da reserva', 'documento_referencia' => 'NF-2026-0003',
            'itens' => [['material_id' => $pistola->id, 'identificador' => 'PAT-0201', 'numero_serie' => 'SN-B1']],
        ], (string) Str::uuid());
    }
}
