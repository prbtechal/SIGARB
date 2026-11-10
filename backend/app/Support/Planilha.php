<?php

namespace App\Support;

/**
 * Planilha .xlsx mínima, sem dependência (o contêiner não tem a extensão zip).
 * Escreve um ZIP sem compressão com células de texto (texto nunca vira fórmula) e lê a primeira aba de volta como linhas.
 */
class Planilha
{
    private const LIMITE_XML = 20 * 1024 * 1024; // proteção contra zip bomb

    /** @param list<string> $cab */
    public static function gerar(array $cab, iterable $linhas): string
    {
        $esc = fn ($v) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) ($v ?? '')), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $linha = function (array $vals, int $n, int $estilo) use ($esc) {
            $c = '';
            foreach (array_values($vals) as $i => $v) {
                $c .= '<c r="'.self::coluna($i).$n.'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'.$esc($v).'</t></is></c>';
            }

            return "<row r=\"$n\">$c</row>";
        };
        $rows = $linha($cab, 1, 2);
        $n = 1;
        foreach ($linhas as $l) {
            $rows .= $linha((array) $l, ++$n, 1);
        }
        $ultima = self::coluna(max(count($cab) - 1, 0));
        $folha = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            ."<cols><col min=\"1\" max=\"".max(count($cab), 1)."\" width=\"24\" style=\"1\" customWidth=\"1\"/></cols>" // coluna em formato Texto: Excel não converte datas/zeros à esquerda
            ."<sheetData>$rows</sheetData><autoFilter ref=\"A1:{$ultima}1\"/></worksheet>";

        return self::zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Dados" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Dados!$A$1:$'.$ultima.'$1</definedName></definedNames></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf/><xf numFmtId="49" applyNumberFormat="1"/><xf numFmtId="49" fontId="1" applyNumberFormat="1" applyFont="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => $folha,
        ]);
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
            $l = [];
            foreach ($row->c as $c) {
                $i = self::indice(preg_replace('/\d+/', '', (string) $c['r']));
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
        for ($k = 0; $k < $n && substr($b, $p, 4) === "PK\x01\x02"; $k++) {
            $h = unpack('vmetodo/x8/Vtam/Vorig/vnome/vextra/vcoment/x8/Vlocal', substr($b, $p + 10, 36));
            $nome = substr($b, $p + 46, $h['nome']);
            $p += 46 + $h['nome'] + $h['extra'] + $h['coment'];
            if (! str_ends_with($nome, '.xml')) {
                continue;
            }
            $loc = unpack('vnome/vextra', substr($b, $h['local'] + 26, 4));
            $bruto = substr($b, $h['local'] + 30 + $loc['nome'] + $loc['extra'], $h['tam']);
            $total += $h['orig'];
            if ($total > self::LIMITE_XML) {
                throw new SigarbException('ARQUIVO_INVALIDO', 'Planilha grande demais depois de descompactada.', 422);
            }
            $c = match ($h['metodo']) { 0 => $bruto, 8 => @gzinflate($bruto, self::LIMITE_XML), default => false };
            if ($c === false) {
                throw new SigarbException('ARQUIVO_INVALIDO', 'Planilha .xlsx com compressão não suportada.', 422);
            }
            $out[$nome] = $c;
        }

        return $out;
    }
}
