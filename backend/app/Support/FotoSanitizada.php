<?php

namespace App\Support;

/**
 * Foto de perfil SEM metadados e sem dependência de extensão (GD/Imagick ausentes no runtime).
 * JPEG: reconstrói o arquivo só com SOI, quantização/Huffman/frame/scan e EOI (descarta APPn — EXIF/GPS/XMP —, COM).
 * PNG: mantém só IHDR, PLTE, tRNS, IDAT, IEND com CRC conferido (descarta tEXt/iTXt/zTXt/eXIf/tIME etc.).
 * Qualquer estrutura inesperada → null (o upload é recusado).
 */
class FotoSanitizada
{
    public const MAX_BYTES = 3 * 1024 * 1024;

    /** @return array{0: string, 1: string}|null [conteúdo limpo, extensão] */
    public static function limpar(string $b): ?array
    {
        if (strlen($b) > self::MAX_BYTES) {
            return null;
        }
        if (str_starts_with($b, "\xFF\xD8\xFF")) {
            return ($l = self::jpeg($b)) ? [$l, 'jpg'] : null;
        }
        if (str_starts_with($b, "\x89PNG\r\n\x1a\n")) {
            return ($l = self::png($b)) ? [$l, 'png'] : null;
        }

        return null;
    }

    private static function jpeg(string $b): ?string
    {
        $out = "\xFF\xD8";
        $i = 2;
        $n = strlen($b);
        $temFrame = false;
        while ($i + 4 <= $n) {
            if ($b[$i] !== "\xFF") {
                return null;
            }
            $m = ord($b[$i + 1]);
            if ($m === 0xFF) { // preenchimento
                $i++;

                continue;
            }
            $len = unpack('n', substr($b, $i + 2, 2))[1];
            if ($len < 2 || $i + 2 + $len > $n) {
                return null;
            }
            $seg = substr($b, $i, 2 + $len);
            if ($m >= 0xC0 && $m <= 0xCF && ! in_array($m, [0xC4, 0xC8, 0xCC], true)) {
                $temFrame = true;
            }
            if ($m === 0xDA) { // início do scan: copia até o EOI final
                $fim = strrpos($b, "\xFF\xD9");
                if (! $temFrame || $fim === false || $fim < $i) {
                    return null;
                }

                return $out.substr($b, $i, $fim + 2 - $i);
            }
            $descartar = ($m >= 0xE0 && $m <= 0xEF) || $m === 0xFE; // APPn e COM
            if (! $descartar) {
                $out .= $seg;
            }
            $i += 2 + $len;
        }

        return null;
    }

    private static function png(string $b): ?string
    {
        $out = substr($b, 0, 8);
        $i = 8;
        $n = strlen($b);
        $vistos = [];
        while ($i + 12 <= $n) {
            $len = unpack('N', substr($b, $i, 4))[1];
            $tipo = substr($b, $i + 4, 4);
            if ($len > $n || $i + 12 + $len > $n) {
                return null;
            }
            $dados = substr($b, $i + 8, $len);
            if (unpack('N', substr($b, $i + 8 + $len, 4))[1] !== crc32($tipo.$dados)) {
                return null;
            }
            if (in_array($tipo, ['IHDR', 'PLTE', 'tRNS', 'IDAT', 'IEND'], true)) {
                $out .= substr($b, $i, 12 + $len);
                $vistos[$tipo] = true;
            }
            $i += 12 + $len;
            if ($tipo === 'IEND') {
                return isset($vistos['IHDR'], $vistos['IDAT']) ? $out : null;
            }
        }

        return null;
    }
}
