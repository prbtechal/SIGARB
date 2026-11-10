<?php

namespace App\Support;

/**
 * Planilha .xlsx mínima, sem dependência (o contêiner não tem a extensão zip).
 * Escreve um ZIP sem compressão com células de texto (texto nunca vira fórmula) e lê a primeira aba de volta como linhas.
 */
class Planilha
{
    private const LIMITE_XML = 20 * 1024 * 1024; // proteção contra zip bomb

    private const MAX_LINHAS = 2001; // 2.000 linhas + cabeçalho (mesmo limite do CSV)

    private const MAX_COLUNAS = 60;

    private const NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

    /**
     * Com $titulo: faixa com as logos (Ronda no Bairro e SEPREV), título e instrução nas linhas 1–3; tabela do Excel a partir da linha 4.
     * Sem $titulo: só a tabela, a partir da linha 1.
     *
     * @param  list<string>  $cab
     */
    public static function gerar(array $cab, iterable $linhas, ?string $titulo = null, ?string $instrucao = null): string
    {
        $esc = fn ($v) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) ($v ?? '')), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cel = fn (string $ref, $v, int $s) => '<c r="'.$ref.'" s="'.$s.'" t="inlineStr"><is><t xml:space="preserve">'.$esc($v).'</t></is></c>';
        $nc = max(count($cab), 1);
        $ultima = self::coluna($nc - 1);
        $topo = $titulo === null ? 0 : 3;
        $rows = '';
        if ($titulo !== null) {
            $rows .= '<row r="1" ht="48" customHeight="1">'.$cel('A1', '', 3).'</row>'
                .'<row r="2" ht="24" customHeight="1">'.$cel('A2', $titulo, 3).'</row>'
                .'<row r="3" ht="30" customHeight="1">'.$cel('A3', $instrucao ?? '', 4).'</row>';
        }
        $n = $topo + 1;
        $rows .= "<row r=\"$n\" ht=\"20\" customHeight=\"1\">".implode('', array_map(fn ($i) => $cel(self::coluna($i).$n, $cab[$i], 5), array_keys($cab))).'</row>';
        $larg = array_map(fn ($c) => mb_strlen((string) $c) + 6, $cab);
        foreach ($linhas as $l) {
            $n++;
            $c = '';
            foreach (array_values((array) $l) as $i => $v) {
                $c .= $cel(self::coluna($i).$n, $v, ($n - $topo) % 2 ? 7 : 6); // listras
                $larg[$i] = max($larg[$i] ?? 10, min(mb_strlen((string) $v) + 3, 60));
            }
            $rows .= "<row r=\"$n\">$c</row>";
        }
        $fimTab = max($n, $topo + 2); // tabela do Excel exige ao menos uma linha de dados
        $cols = implode('', array_map(fn ($i) => '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.max(16, $larg[$i] ?? 16).'" style="1" customWidth="1"/>', range(0, $nc - 1)));
        $merge = $titulo === null ? '' : '<mergeCells count="3"><mergeCell ref="A1:'.$ultima.'1"/><mergeCell ref="A2:'.$ultima.'2"/><mergeCell ref="A3:'.$ultima.'3"/></mergeCells>';
        $folha = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet '.self::NS.'>'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="'.($topo + 1).'" topLeftCell="A'.($topo + 2).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            ."<cols>$cols</cols><sheetData>$rows</sheetData>$merge"
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            .($titulo === null ? '' : '<drawing r:id="rId2"/>').'<tableParts count="1"><tablePart r:id="rId1"/></tableParts></worksheet>';
        $tabela = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="1" name="Dados" displayName="Dados" ref="A'.($topo + 1).':'.$ultima.$fimTab.'">'
            .'<autoFilter ref="A'.($topo + 1).':'.$ultima.$fimTab.'"/><tableColumns count="'.$nc.'">'
            .implode('', array_map(fn ($i) => '<tableColumn id="'.($i + 1).'" name="'.$esc(self::nomeColuna($cab, $i)).'"/>', range(0, $nc - 1)))
            .'</tableColumns><tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/></table>';

        $arquivos = [
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook '.self::NS.'><sheets><sheet name="Dados" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            // 0 padrão · 1 texto · 2 (reservado) · 3 título azul-marinho · 4 instrução · 5 cabeçalho · 6/7 linhas listradas.
            // Cores nas próprias células (além do estilo da tabela) para ficarem iguais no Excel e no LibreOffice.
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="15"/><color rgb="FF0B1F66"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
                .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0B1F66"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EEFB"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border/><border><left style="thin"><color rgb="FFC7D2E8"/></left><right style="thin"><color rgb="FFC7D2E8"/></right><top style="thin"><color rgb="FFC7D2E8"/></top><bottom style="thin"><color rgb="FFC7D2E8"/></bottom></border></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
                .'<cellXfs count="8"><xf/><xf numFmtId="49" applyNumberFormat="1"/><xf numFmtId="49" applyNumberFormat="1"/>'
                .'<xf numFmtId="49" fontId="1" applyNumberFormat="1" applyFont="1" applyAlignment="1"><alignment vertical="center"/></xf>'
                .'<xf numFmtId="49" fontId="2" applyNumberFormat="1" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
                .'<xf numFmtId="49" fontId="3" fillId="2" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'
                .'<xf numFmtId="49" borderId="1" applyNumberFormat="1" applyBorder="1"/><xf numFmtId="49" fillId="3" borderId="1" applyNumberFormat="1" applyFill="1" applyBorder="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => $folha,
            'xl/tables/table1.xml' => $tabela,
        ];
        $rels = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table1.xml"/>';
        $tipos = '<Override PartName="/xl/tables/table1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
        if ($titulo !== null) {
            $rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>';
            $tipos .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
            $arquivos += self::logos();
        }
        $arquivos['xl/worksheets/_rels/sheet1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
        $arquivos['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$tipos.'</Types>';

        return self::zip($arquivos);
    }

    /** Nome de coluna da tabela: não vazio e único (exigência do Excel). */
    private static function nomeColuna(array $cab, int $i): string
    {
        $nome = trim((string) ($cab[$i] ?? '')) ?: 'Coluna'.($i + 1);

        return in_array($nome, array_slice($cab, 0, $i), true) ? $nome.' '.($i + 1) : $nome;
    }

    /** Logos da faixa do topo: Ronda no Bairro à esquerda, SEPREV ao lado (mesmas imagens da barra lateral). */
    private static function logos(): array
    {
        $emu = 9525; // EMU por pixel
        $img = fn (int $id, int $x, int $w, int $h) => '<xdr:absoluteAnchor><xdr:pos x="'.($x * $emu).'" y="'.(6 * $emu).'"/><xdr:ext cx="'.($w * $emu).'" cy="'.($h * $emu).'"/>'
            .'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="'.($id + 1).'" name="Logo '.$id.'"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            .'<xdr:blipFill><a:blip r:embed="rId'.$id.'"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.($w * $emu).'" cy="'.($h * $emu).'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:absoluteAnchor>';
        $dir = resource_path('planilha');

        return [
            'xl/drawings/drawing1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .$img(1, 8, 52, 52).$img(2, 72, 290, 52).'</xdr:wsDr>', // 529x95 → 290x52 mantém a proporção
            'xl/drawings/_rels/drawing1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image2.png"/></Relationships>',
            'xl/media/image1.png' => (string) file_get_contents("$dir/logo-ronda-do-bairro.png"),
            'xl/media/image2.png' => (string) file_get_contents("$dir/Logo-seprev.png"),
        ];
    }

    /** Primeira aba como linhas de texto (cabeçalho incluso). */
    public static function ler(string $bytes): array
    {
        $arqs = self::unzip($bytes);
        $folha = $arqs['xl/worksheets/sheet1.xml'] ?? null; // shortcut: lê sheet1 (a primeira aba que o Excel grava); multiabas exigiria ler workbook.xml
        if ($folha === null) {
            throw new SigarbException('ARQUIVO_INVALIDO', 'Planilha .xlsx inválida ou sem a primeira aba.', 422);
        }
        $comp = [];
        if (isset($arqs['xl/sharedStrings.xml'])) {
            foreach (self::xml($arqs['xl/sharedStrings.xml'])->si as $si) {
                $comp[] = self::texto($si);
            }
        }
        $linhas = [];
        foreach (self::xml($folha)->sheetData->row ?? [] as $row) {
            if (count($linhas) >= self::MAX_LINHAS) {
                throw new SigarbException('ARQUIVO_INVALIDO', 'Planilha com linhas demais (limite '.self::MAX_LINHAS.').', 422);
            }
            $l = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r']; // r é opcional no formato: sem ele, a célula é a seguinte
                $i = $ref === '' ? ($l ? max(array_keys($l)) + 1 : 0) : (preg_match('/^([A-Z]{1,3})\d+$/', $ref, $m) ? self::indice($m[1]) : self::MAX_COLUNAS);
                if ($i >= self::MAX_COLUNAS) {
                    throw new SigarbException('ARQUIVO_INVALIDO', 'Planilha com célula fora do limite de '.self::MAX_COLUNAS.' colunas.', 422);
                }
                $t = (string) $c['t'];
                $l[$i] = match ($t) {
                    's' => $comp[(int) $c->v] ?? '',
                    'inlineStr' => self::texto($c->is),
                    default => (string) $c->v,
                };
            }
            if ($l) {
                $max = max(array_keys($l));
                $linhas[] = array_map(fn ($k) => $l[$k] ?? '', range(0, $max));
            }
        }

        return $linhas;
    }

    private static function texto(\SimpleXMLElement $e): string
    {
        $t = isset($e->t) ? (string) $e->t : '';
        foreach ($e->r ?? [] as $r) {
            $t .= (string) $r->t;
        }

        return $t;
    }

    private static function xml(string $s): \SimpleXMLElement
    {
        $x = @simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET); // sem entidades externas (XXE)
        if ($x === false) {
            throw new SigarbException('ARQUIVO_INVALIDO', 'Planilha .xlsx corrompida.', 422);
        }

        return $x;
    }

    private static function coluna(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private static function indice(string $col): int
    {
        $n = 0;
        foreach (str_split(strtoupper($col)) as $ch) {
            $n = $n * 26 + ord($ch) - 64;
        }

        return $n - 1;
    }

    /** @param array<string, string> $arquivos */
    private static function zip(array $arquivos): string
    {
        $dados = $central = '';
        foreach ($arquivos as $nome => $conteudo) {
            $cab = pack('vvvvvVVVvv', 20, 0x0800, 0, 0, 0x21, crc32($conteudo), strlen($conteudo), strlen($conteudo), strlen($nome), 0);
            $central .= pack('V', 0x02014b50).pack('v', 20).$cab.pack('vvvVV', 0, 0, 0, 0, strlen($dados)).$nome;
            $dados .= pack('V', 0x04034b50).$cab.$nome.$conteudo;
        }

        return $dados.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, count($arquivos), count($arquivos), strlen($central), strlen($dados), 0);
    }

    /** @return array<string, string> */
    private static function unzip(string $b): array
    {
        $fim = strrpos($b, "PK\x05\x06");
        if ($fim === false || strlen($b) < $fim + 22) {
            throw new SigarbException('ARQUIVO_INVALIDO', 'O arquivo não é uma planilha .xlsx válida.', 422);
        }
        $p = unpack('Voff', substr($b, $fim + 16, 4))['off'];
        $n = unpack('vn', substr($b, $fim + 10, 2))['n'];
        $out = [];
        $total = 0;
        for ($k = 0; $k < min($n, 1000) && substr($b, $p, 4) === "PK\x01\x02"; $k++) {
            $h = unpack('vmetodo/x8/Vtam/Vorig/vnome/vextra/vcoment/x8/Vlocal', substr($b, $p + 10, 36));
            $nome = substr($b, $p + 46, $h['nome']);
            $p += 46 + $h['nome'] + $h['extra'] + $h['coment'];
            if (! in_array($nome, ['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml'], true)) {
                continue; // só infla o que é lido
            }
            $loc = unpack('vnome/vextra', substr($b, $h['local'] + 26, 4));
            $bruto = substr($b, $h['local'] + 30 + $loc['nome'] + $loc['extra'], $h['tam']);
            $resta = self::LIMITE_XML - $total;
            $c = match ($h['metodo']) { 0 => $bruto, 8 => @gzinflate($bruto, max($resta, 1)), default => false }; // falha se passar do orçamento
            if ($c !== false && ($total += strlen($c)) > self::LIMITE_XML) {
                throw new SigarbException('ARQUIVO_INVALIDO', 'Planilha grande demais depois de descompactada.', 422);
            }
            if ($c === false) {
                throw new SigarbException('ARQUIVO_INVALIDO', 'Planilha .xlsx com compressão não suportada.', 422);
            }
            $out[$nome] = $c;
        }

        return $out;
    }
}
