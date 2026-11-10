<?php

namespace Tests\Feature;

use App\Support\Planilha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Modelo, exportação e importação também em .xlsx (além de CSV). */
class PlanilhaXlsxTest extends TestCase
{
    use RefreshDatabase;

    public function test_ida_e_volta_preserva_texto_acentos_e_zeros(): void
    {
        $linhas = Planilha::ler(Planilha::gerar(['nome', 'codigo'], [['Fabricação Ação', '0123'], ['=1+1', '']]));
        $this->assertSame([['nome', 'codigo'], ['Fabricação Ação', '0123'], ['=1+1', '']], $linhas);
    }

    public function test_le_xlsx_compactado_com_textos_compartilhados(): void
    {
        // Mesmo formato que o Excel grava: deflate + sharedStrings.
        $z = new \PharData(sys_get_temp_dir().'/x'.uniqid().'.zip'); // PharData grava zip com deflate sem ext-zip
        $z->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>nome</t></si><si><r><t>Fab </t></r><r><t>Excel</t></r></si></sst>');
        $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c></row></sheetData></worksheet>');
        $z->compressFiles(\Phar::GZ);
        $this->assertSame([['nome'], ['Fab Excel']], Planilha::ler(file_get_contents($z->getPath())));
    }

    public function test_baixa_modelo_xlsx_e_importa_xlsx(): void
    {
        $this->cenario();
        $this->como($this->c['admin']);
        $r = $this->get('/api/v1/data-transfer/fabricantes/template?formato=xlsx')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertSame('nome*', Planilha::ler($r->getContent())[0][0]);
        $this->get('/api/v1/data-transfer/fabricantes/export?formato=xlsx')->assertOk()->assertHeader('Content-Disposition');

        $arq = UploadedFile::fake()->createWithContent('fabricantes.xlsx', Planilha::gerar(['nome'], [['Fab Planilha']]));
        $this->post('/api/v1/data-transfer/fabricantes/import', ['arquivo' => $arq, 'simulacao' => 0])->assertOk()->assertJsonPath('gravadas', 1);
        $this->assertDatabaseHas('fabricantes', ['nome' => 'Fab Planilha']);
    }

    public function test_recusa_xlsx_corrompido(): void
    {
        $this->cenario();
        $arq = UploadedFile::fake()->createWithContent('ruim.xlsx', "PK\x03\x04lixo");
        $this->como($this->c['admin'])->post('/api/v1/data-transfer/fabricantes/import', ['arquivo' => $arq, 'simulacao' => 1])->assertStatus(422);
    }

    public function test_recusa_coluna_absurda_e_linhas_demais(): void
    {
        $this->expectException(\App\Support\SigarbException::class);
        $z = new \PharData(sys_get_temp_dir().'/y'.uniqid().'.zip');
        $z->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="XFD1" t="inlineStr"><is><t>x</t></is></c></row></sheetData></worksheet>');
        Planilha::ler(file_get_contents($z->getPath()));
    }

    public function test_recusa_mais_de_duas_mil_linhas(): void
    {
        $this->expectException(\App\Support\SigarbException::class);
        Planilha::ler(Planilha::gerar(['nome'], array_fill(0, 2001, ['x'])));
    }
}
