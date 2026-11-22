<?php

declare(strict_types=1);

namespace Bic\Core\Export;

/**
 * PDF de tabla sin librerías: Helvetica estándar (WinAnsi, con acentos y ñ), encabezado repetido por página,
 * paginación «Página n de N», números alineados a la derecha, horizontal si hay muchas columnas.
 */
final class PdfWriter
{
    /** Anchos Helvetica (1/1000 em) para ASCII 32–126. */
    private const W = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015,
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333,
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584];

    /** @param string $text UTF-8 */
    private static function enc(string $text): string
    {
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);

        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '' : $out;
    }

    private static function width(string $cp1252, float $size): float
    {
        $w = 0;
        $n = strlen($cp1252);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($cp1252[$i]);
            $w += ($c >= 32 && $c <= 126) ? self::W[$c - 32] : 556;
        }

        return $w * $size / 1000;
    }

    private static function esc(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s);
    }

    private static function fit(string $cp, float $size, float $max): string
    {
        if (self::width($cp, $size) <= $max) {
            return $cp;
        }
        while ($cp !== '' && self::width($cp . '...', $size) > $max) {
            $cp = substr($cp, 0, -1);
        }

        return $cp . '...';
    }

    public static function render(Table $t, string $footerNote = ''): string
    {
        $landscape = count($t->columns) > 6;
        $pw = $landscape ? 841.89 : 595.28;
        $ph = $landscape ? 595.28 : 841.89;
        $m = 36.0;
        $size = 8.5;
        $pad = 4.0;
        $rowH = 15.0;
        $avail = $pw - 2 * $m;

        // anchos de columna según contenido (muestra de hasta 200 filas), escalados al ancho útil
        $all = $t->allRows();
        $natural = [];
        foreach ($t->columns as $i => $c) {
            $w = self::width(self::enc($c['label']), $size) * 1.12 + 2 * $pad; // cabecera en negrita
            foreach (array_slice($all, 0, 200) as $row) {
                $w = max($w, self::width(self::enc($t->display($c, $row[$c['key']] ?? null)), $size) + 2 * $pad);
            }
            $natural[$i] = min($w, 220.0);
        }
        $sum = array_sum($natural);
        $scale = $sum > 0 ? $avail / $sum : 1.0;
        $widths = array_map(static fn (float $w): float => $w * $scale, $natural);
        if ($scale > 1.0) { // tabla estrecha: no estirar, queda alineada a la izquierda
            $widths = $natural;
        }

        $pages = [];
        $content = '';
        $y = 0.0;
        $newPage = function () use (&$content, &$y, &$pages, $ph, $m, $t, $widths, $size, $pad, $rowH, $pw): void {
            if ($content !== '') {
                $pages[] = $content;
            }
            $content = '';
            $y = $ph - $m;
            if (!$pages) { // primera página: título
                $content .= sprintf("BT /F2 15 Tf 0.298 0.114 0.576 rg %.2f %.2f Td (%s) Tj ET\n", $m, $y - 12, self::esc(self::enc($t->title)));
                $content .= sprintf("BT /F1 9 Tf 0.467 0.451 0.541 rg %.2f %.2f Td (%s) Tj ET\n", $m, $y - 27, self::esc(self::enc(self::fit(self::enc($t->subtitle), 9, $pw - 2 * $m))));
                $y -= 42;
            }
            // encabezado
            $x = $m;
            $total = array_sum($widths);
            $content .= sprintf("0.486 0.227 0.929 rg %.2f %.2f %.2f %.2f re f\n", $m, $y - $rowH, $total, $rowH);
            foreach ($t->columns as $i => $c) {
                $label = self::fit(self::enc($c['label']), $size, $widths[$i] - 2 * $pad);
                $tx = Table::isNumeric($t->type($c)) ? $x + $widths[$i] - $pad - self::width($label, $size) : $x + $pad;
                $content .= sprintf("BT /F2 %.1f Tf 1 1 1 rg %.2f %.2f Td (%s) Tj ET\n", $size, $tx, $y - $rowH + 4.5, self::esc($label));
                $x += $widths[$i];
            }
            $y -= $rowH;
        };
        $newPage();
        if ($all === []) {
            $content .= sprintf("BT /F1 10 Tf 0.467 0.451 0.541 rg %.2f %.2f Td (%s) Tj ET\n", $m, $y - 18, self::esc(self::enc('Sin datos para este período.')));
        }
        $last = count($all) - 1;
        foreach ($all as $k => $row) {
            if ($y - $rowH < $m + 20) {
                $newPage();
            }
            $isTotal = $t->totals !== null && $k === $last;
            $total = array_sum($widths);
            if ($isTotal) {
                $content .= sprintf("0.945 0.922 0.996 rg %.2f %.2f %.2f %.2f re f\n", $m, $y - $rowH, $total, $rowH);
            } elseif ($k % 2 === 1) {
                $content .= sprintf("0.973 0.969 0.988 rg %.2f %.2f %.2f %.2f re f\n", $m, $y - $rowH, $total, $rowH);
            }
            $x = $m;
            foreach ($t->columns as $i => $c) {
                $txt = self::fit(self::enc($t->display($c, $row[$c['key']] ?? null)), $size, $widths[$i] - 2 * $pad);
                $tx = Table::isNumeric($t->type($c)) ? $x + $widths[$i] - $pad - self::width($txt, $size) : $x + $pad;
                $content .= sprintf("BT /%s %.1f Tf 0.122 0.106 0.176 rg %.2f %.2f Td (%s) Tj ET\n", $isTotal ? 'F2' : 'F1', $size, $tx, $y - $rowH + 4.5, self::esc($txt));
                $x += $widths[$i];
            }
            $content .= sprintf("0.914 0.898 0.949 RG 0.4 w %.2f %.2f m %.2f %.2f l S\n", $m, $y - $rowH, $m + $total, $y - $rowH);
            $y -= $rowH;
        }
        $pages[] = $content;

        // ensamblado
        $n = count($pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (5 + $i * 2) . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $foot = self::enc($footerNote);
        foreach ($pages as $i => $c) {
            $c .= sprintf("BT /F1 8 Tf 0.467 0.451 0.541 rg %.2f %.2f Td (%s) Tj ET\n", $m, 20, self::esc(self::fit($foot, 8, $pw - 2 * $m - 90)));
            $label = self::enc('Página ' . ($i + 1) . ' de ' . $n);
            $c .= sprintf("BT /F1 8 Tf 0.467 0.451 0.541 rg %.2f %.2f Td (%s) Tj ET\n", $pw - $m - self::width($label, 8), 20, self::esc($label));
            $stream = function_exists('gzcompress') ? gzcompress($c, 6) : false;
            $filter = $stream !== false ? ' /Filter /FlateDecode' : '';
            $data = $stream !== false ? $stream : $c;
            $objs[5 + $i * 2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . round($pw, 2) . ' ' . round($ph, 2) . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . (6 + $i * 2) . ' 0 R >>';
            $objs[6 + $i * 2] = '<< /Length ' . strlen($data) . $filter . ' >>' . "\nstream\n" . $data . "\nendstream";
        }
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objs) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
