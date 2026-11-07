<?php

namespace App\Support;

/**
 * Foto de perfil SEM metadados e sem dependência de extensão (GD/Imagick ausentes no runtime).
 * JPEG: percorre todos os segmentos e scans (inclusive progressivos); descarta APPn (EXIF/GPS/XMP) e COM em qualquer posição
 *       e tudo após o primeiro EOI.
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
        while ($i + 2 <= $n) {
            if ($b[$i] !== "\xFF") {
                return null;
            }
            $m = ord($b[$i + 1]);
            if ($m === 0xFF) { // preenchimento
                $i++;

                continue;
            }
            if ($m === 0xD9) { // EOI: fim; bytes posteriores são descartados
                return $temFrame ? $out."\xFF\xD9" : null;
            }
            if ($i + 4 > $n) {
                return null;
            }
            $len = unpack('n', substr($b, $i + 2, 2))[1];
            if ($len < 2 || $i + 2 + $len > $n) {
                return null;
            }
            if ($m >= 0xC0 && $m <= 0xCF && ! in_array($m, [0xC4, 0xC8, 0xCC], true)) {
                $temFrame = true;
            }
            $descartar = ($m >= 0xE0 && $m <= 0xEF) || $m === 0xFE; // APPn (EXIF/GPS/XMP) e COM, em qualquer posição
            if (! $descartar) {
                $out .= substr($b, $i, 2 + $len);
            }
            $i += 2 + $len;
            if ($m === 0xDA) { // dados do scan: até o próximo marcador real (FF00 = byte literal; FFD0..D7 = RSTn)
                if (! $temFrame) {
                    return null;
                }
                $ini = $i;
                while ($i + 1 < $n) {
                    if ($b[$i] === "\xFF") {
                        $p = ord($b[$i + 1]);
                        if ($p === 0x00 || ($p >= 0xD0 && $p <= 0xD7)) {
                            $i += 2;

                            continue;
                        }
                        break;
                    }
                    $i++;
                }
                if ($i + 1 >= $n) {
                    return null; // scan sem marcador final
                }
                $out .= substr($b, $ini, $i - $ini);
            }
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
