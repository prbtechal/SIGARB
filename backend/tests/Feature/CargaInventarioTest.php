<?php

namespace Tests\Feature;

use App\Models\AtribuicaoPapel;
use App\Models\EventoAuditoria;
use App\Models\ItemIndividual;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\Movimentacao;
use App\Models\Papel;
use App\Models\SaldoEstoque;
use App\Models\Unidade;
use App\Services\CargaInventarioService;
use App\Support\SigarbException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Carga do inventário de material bélico a partir de planilha .xlsx — dados SINTÉTICOS (séries falsas). */
class CargaInventarioTest extends TestCase
{
    use RefreshDatabase;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();
        // Estrutura PRB pelas próprias migrações (idempotentes): outros testes da suíte truncam as tabelas.
        foreach (['2026_11_17_000200_estrutura_prb', '2026_11_17_000300_estrutura_prb_raiz_e_secoes', '2026_11_18_000100_remover_secoes_poco_sem_uso'] as $m) {
            (require database_path("migrations/$m.php"))->up();
        }
        $this->cenario();
        // Quem assina a carga: Administrador + Gestor na raiz PRB (ADMIN sozinho não registra entrada de estoque).
        AtribuicaoPapel::create(['usuario_id' => $this->c['admin']->id, 'papel_id' => Papel::where('codigo', 'GESTOR')->value('id'),
            'unidade_id' => Unidade::where('sigla', 'PRB')->value('id')]);
        $this->arquivo = $this->xlsx([
            'Poço' => [
                ['POÇO – Inventário (teste)'], [], [],
                ['Resumo', 'Pistolas PT 100 .40', 'Tasers X2', 'Munições .40', 'Coletes P', 'Coletes M', 'Coletes G', 'Algemas', 'Rádios HT Hytera', 'Acessórios Taser'],
                ['Poço', '2', '1', '30', '1', '0', '0', '1', '0', '0'], [],
                ['3.1 ARMAMENTO'], ['Seção no documento: RESERVA POÇO'], ['#', 'Descrição', 'Nº de série', 'Origem', 'Observações', 'Qtd'],
                ['1', 'PT 100 Cal. .40 Taurus', 'TST 00001', 'GMG', '', '1'],
                ['2', 'PT 100 Cal. .40 Taurus', 'TST 00002', 'AMG', 'Nota sintética', '1'],
                ['', '', '', '', 'SUBTOTAL', '2'], [],
                ['3.2 TASER – DISPOSITIVO DE ENERGIA'], ['Seção no documento: BASE POÇO'], ['#', 'Descrição', 'Nº de série', 'Origem', 'Situação', 'Qtd'],
                ['1', 'Taser X2', 'TSTX2A', 'CMB', 'Baixado', '1'], [],
                ['3.3 MUNIÇÕES'], ['Seção no documento: RESERVA POÇO'], ['#', 'Descrição', 'Origem', 'Situação', 'Qtd'],
                ['1', 'Munições Cal. .40 TESTE', 'CMB', '', '30'], ['', '', '', 'SUBTOTAL', '30'], [],
                ['3.4 COLETES BALÍSTICOS'], ['Seção no documento: RESERVA POÇO'],
                ['#', 'Descrição', 'Tamanho', 'Nº de série', 'Origem', 'Venc. refil', 'Situação', 'Observações', 'Qtd'],
                ['1', 'Colete Balístico', 'P', 'TST-C1', 'AMG', '07-2027', '', 'Nota do documento: “P”', '1'], [],
                ['3.5 ALGEMAS'], ['Seção no documento: RESERVA POÇO'], ['#', 'Descrição', 'Numeração', 'Origem', 'Situação', 'Qtd'],
                ['1', 'Algema de Pulso em Aço', 'S/N', 'SERIS', '', '1'],
            ],
            'Outros Setores' => [
                ['OUTROS SETORES'], [], [],
                ['Resumo', 'Pistolas PT 100 .40', 'Rádios HT Hytera'], ['Logística', '0', '1'], ['COPOM', '0', '1'], [],
                ['LOGÍSTICA'], ['3.6 MATERIAIS DE COMUNICAÇÃO – RÁDIO HT DIGITAL HYTERA'], ['Seção no documento: RÁDIO HT DIGITAL HYTERA – LOGÍSTICA'],
                ['#', 'Descrição', 'TEI', 'ISSI', 'Serial', 'Patrimônio', 'Qtd'], ['1', 'Rádio HT Digital Hytera', 'TEI-T1', '901', 'TSTSER1', 'PAT-1', '1'], [],
                ['COPOM'], ['3.6 MATERIAIS DE COMUNICAÇÃO – RÁDIO HT DIGITAL HYTERA'], ['Seção no documento: RÁDIO HT DIGITAL HYTERA – COPOM'],
                ['#', 'Descrição', 'TEI', 'ISSI', 'Serial', 'Patrimônio', 'Qtd'], ['1', 'Rádio HT Digital Hytera', 'TEI-T2', '902', 'TSTSER2', 'PAT-2', '1'],
            ],
            'Conferência' => [
                ['CONFERÊNCIA'], [], [], ['Tabela nº', 'Categoria', 'Seção no documento', 'Território', 'Situação', 'Total declarado', 'Total contado', 'Diferença', 'Status'],
                ['1', 'Armamento', 'RESERVA POÇO', 'Poço', '', '2', '2', '0', 'OK'],
                ['15', 'Munição', 'RESERVA POÇO', 'Poço', '', '40', '30', '-10', 'DIVERGE'],
            ],
            'Info do Documento' => [['Comissão – Presidente', 'PESSOA FICTICIA']],
        ]);
    }

    public function test_le_formatos_de_secao(): void
    {
        $d = app(CargaInventarioService::class)->ler((string) file_get_contents($this->arquivo));
        $this->assertCount(8, $d['registros']);
        $por = collect($d['registros'])->groupBy('cat')->map->count()->all();
        $this->assertSame(['ARMAMENTO' => 2, 'TASER' => 1, 'MUNICAO' => 1, 'COLETE' => 1, 'ALGEMA' => 1, 'COMUNICACAO' => 2], $por);
        $radio = collect($d['registros'])->firstWhere('serie', 'TSTSER2');
        $this->assertSame(['COPOM', 'TEI-T2', '902', 'PAT-2'], [$radio['grupo'], $radio['tei'], $radio['issi'], $radio['patrimonio']]);
        $this->assertSame('30', collect($d['registros'])->firstWhere('cat', 'MUNICAO')['qtd']);
        $this->assertSame(30, $d['resumos']['POCO']['MUNICOES']);
        $this->assertSame(1, $d['resumos']['COPOM']['RADIOS']);
        $this->assertCount(2, $d['conferencia']);
    }

    public function test_simular_nao_grava_nada(): void
    {
        $antes = [Movimentacao::count(), ItemIndividual::count(), LocalEstoque::count(), Material::count(), EventoAuditoria::count()];
        $this->artisan('sigarb:inventario:importar', ['arquivo' => $this->arquivo, '--simular' => true, '--usuario' => $this->c['admin']->id])
            ->expectsOutputToContain('SIMULAÇÃO')->expectsOutputToContain('novas: 8')->assertExitCode(0);
        $this->assertSame($antes, [Movimentacao::count(), ItemIndividual::count(), LocalEstoque::count(), Material::count(), EventoAuditoria::count()]);
        $this->assertNull(Unidade::where('sigla', 'COPOM')->first());
    }

    public function test_carga_cria_locais_itens_e_lote_e_e_idempotente(): void
    {
        $admin = $this->c['admin'];
        $this->artisan('sigarb:inventario:importar', ['arquivo' => $this->arquivo, '--usuario' => $admin->id])
            ->expectsOutputToContain('REAL — gravada')
            ->expectsOutputToContain('DIVERGE DO DOCUMENTO') // munição: declarado 40 × contado 30 é reportado
            ->doesntExpectOutputToContain('PESSOA FICTICIA')
            ->assertExitCode(0);

        // Locais por território/setor, nas unidades da estrutura; COPOM criada sob o Poço.
        $poco = Unidade::where('sigla', 'MACEIO-POCO')->firstOrFail();
        $this->assertSame($poco->id, LocalEstoque::where('codigo', 'RES-POCO')->value('unidade_id'));
        $this->assertSame(Unidade::where('sigla', 'POCO-LOGISTICA')->value('id'), LocalEstoque::where('codigo', 'RES-LOGISTICA')->value('unidade_id'));
        $copom = Unidade::where('sigla', 'COPOM')->firstOrFail();
        $this->assertSame($poco->id, $copom->unidade_superior_id);
        $this->assertStringStartsWith($poco->caminho, $copom->caminho);

        // Itens com série e sem série; dados do documento na justificativa da movimentação.
        $pt = ItemIndividual::where('identificador', 'ARM-TST-00001')->firstOrFail();
        $this->assertSame(['TST 00001', 'PT-100-CAL-40-TAURUS', 'PT 100'], [$pt->numero_serie, $pt->material->codigo, $pt->material->modelo]);
        $this->assertTrue(ItemIndividual::where('identificador', 'like', 'ALG-SN-RES-POCO-L%')->whereNull('numero_serie')->exists());
        $this->assertSame('INSERVIVEL', ItemIndividual::where('numero_serie', 'TSTX2A')->value('estado_conservacao'));
        $this->assertSame('P', ItemIndividual::where('numero_serie', 'TST-C1')->firstOrFail()->material->tamanho);
        $radio = ItemIndividual::where('numero_serie', 'TSTSER1')->firstOrFail();
        $mov = Movimentacao::whereHas('itens', fn ($q) => $q->where('item_individual_id', $radio->id))->firstOrFail();
        $this->assertStringContainsString('TEI: TEI-T1', $mov->justificativa);
        $this->assertSame(CargaInventarioService::DOCUMENTO, $mov->documento_referencia);
        $this->assertSame($admin->id, $mov->responsavel_id);

        // Munição por quantidade (lote).
        $this->assertSame(30, (int) SaldoEstoque::where('local_estoque_id', LocalEstoque::where('codigo', 'RES-POCO')->value('id'))->sum('quantidade'));
        $this->assertSame(8, EventoAuditoria::where('acao', 'estoque.entrada')->where('usuario_id', $admin->id)->count());

        // Segunda execução: nada novo.
        $contagem = fn () => [Movimentacao::count(), ItemIndividual::count(), LocalEstoque::count(), Material::count(), (int) SaldoEstoque::sum('quantidade'), Unidade::count()];
        $antes = $contagem();
        $this->artisan('sigarb:inventario:importar', ['arquivo' => $this->arquivo, '--usuario' => $admin->id])
            ->expectsOutputToContain('novas: 0 · já carregadas (idempotência): 8')->assertExitCode(0);
        $this->assertSame($antes, $contagem());
    }

    public function test_exige_permissoes_de_quem_assina(): void
    {
        foreach (['operadorAlfa1' => 'catalogo.gerir', 'gestorAlfa' => 'catalogo.gerir'] as $quem => $falta) {
            try {
                app(CargaInventarioService::class)->executar((string) file_get_contents($this->arquivo), $this->c[$quem], true);
                $this->fail("$quem não deveria assinar a carga");
            } catch (SigarbException $e) {
                $this->assertStringContainsString($falta, $e->getMessage());
            }
        }
        // ADMIN sem papel de estoque: barrado em estoque.entrada (segregação de funções mantida).
        $soAdmin = $this->usuarioCom('ADMIN', null);
        $this->expectExceptionMessage('estoque.entrada');
        app(CargaInventarioService::class)->executar((string) file_get_contents($this->arquivo), $soAdmin, true);
    }

    public function test_migracao_remove_secoes_sem_uso_e_so_desativa_as_referenciadas(): void
    {
        $this->assertNull(Unidade::where('sigla', 'POCO-TI')->first());
        $this->assertNotNull(Unidade::where('sigla', 'POCO-SMB')->first());
        // Recria uma seção removida com referência (local) e reaplica: não pode apagar, só desativar.
        $m = require database_path('migrations/2026_11_18_000100_remover_secoes_poco_sem_uso.php');
        $m->down();
        $ti = Unidade::where('sigla', 'POCO-TI')->firstOrFail();
        LocalEstoque::factory()->create(['unidade_id' => $ti->id]);
        $m->up();
        $this->assertFalse($ti->fresh()->ativo);
        $this->assertNull(Unidade::where('sigla', 'POCO-BANDA')->first());
    }

    /** @param array<string, list<list<string>>> $abas */
    private function xlsx(array $abas): string
    {
        $esc = fn ($v) => htmlspecialchars($v, ENT_XML1);
        $z = new \PharData(sys_get_temp_dir().'/inv'.uniqid().'.zip');
        $sheets = $rels = '';
        $n = 0;
        foreach ($abas as $nome => $linhas) {
            $n++;
            $rows = '';
            foreach ($linhas as $i => $l) {
                $cel = '';
                foreach ($l as $j => $v) {
                    $cel .= $v === '' ? '' : '<c r="'.chr(65 + $j).($i + 1).'" t="inlineStr"><is><t>'.$esc($v).'</t></is></c>';
                }
                $rows .= $cel === '' ? '' : '<row r="'.($i + 1).'">'.$cel.'</row>';
            }
            $z->addFromString("xl/worksheets/sheet$n.xml", '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
            $sheets .= '<sheet name="'.$esc($nome).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        }
        $z->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');

        return $z->getPath();
    }
}
