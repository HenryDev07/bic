<?php

declare(strict_types=1);

namespace Bic\Core\Export;

use RuntimeException;
use ZipArchive;

/** Excel (.xlsx) mínimo y válido, sin librerías: cadenas en línea (nunca fórmulas), números reales y formatos. Requiere ext-zip. */
final class XlsxWriter
{
    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    private static function x(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }

        return $s;
    }

    public static function render(Table $t): string
    {
        if (!self::available()) {
            throw new RuntimeException('La extensión zip de PHP no está habilitada.');
        }
        // estilos: 0 normal, 1 título, 2 cabecera, 3 dinero, 4 cantidad, 5 porcentaje, 6 entero, 7 subtítulo, 8 total texto, 9..12 totales numéricos
        $numStyle = ['money' => 3, 'qty' => 4, 'pct' => 5, 'int' => 6];
        $totStyle = ['money' => 9, 'qty' => 10, 'pct' => 11, 'int' => 12];
        $rows = '';
        $r = 1;
        $rows .= '<row r="' . $r . '"><c r="A' . $r . '" s="1" t="inlineStr"><is><t>' . self::x($t->title) . '</t></is></c></row>';
        $r++;
        $rows .= '<row r="' . $r . '"><c r="A' . $r . '" s="7" t="inlineStr"><is><t>' . self::x($t->subtitle) . '</t></is></c></row>';
        $r += 2;
        $headerRow = $r;
        $cells = '';
        $widths = [];
        foreach ($t->columns as $i => $c) {
            $cells .= '<c r="' . self::col($i) . $r . '" s="2" t="inlineStr"><is><t>' . self::x($c['label']) . '</t></is></c>';
            $widths[$i] = mb_strlen($c['label']) + 2;
        }
        $rows .= '<row r="' . $r . '">' . $cells . '</row>';
        $all = $t->allRows();
        $last = count($all) - 1;
        foreach ($all as $k => $row) {
            $r++;
            $isTotal = $t->totals !== null && $k === $last;
            $cells = '';
            foreach ($t->columns as $i => $c) {
                $type = $t->type($c);
                $v = $row[$c['key']] ?? null;
                $ref = self::col($i) . $r;
                if ($v === null || $v === '') {
                    $cells .= '<c r="' . $ref . '"' . ($isTotal ? ' s="8"' : '') . '/>';
                    continue;
                }
                if (Table::isNumeric($type) && is_numeric((string) $v)) {
                    $s = $isTotal ? $totStyle[$type] : $numStyle[$type];
                    $cells .= '<c r="' . $ref . '" s="' . $s . '"><v>' . self::x((string) $v) . '</v></c>';
                    $widths[$i] = max($widths[$i], mb_strlen($t->display($c, $v)) + 2);
                } else {
                    $cells .= '<c r="' . $ref . '"' . ($isTotal ? ' s="8"' : '') . ' t="inlineStr"><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
                    $widths[$i] = max($widths[$i], min(60, mb_strlen((string) $v) + 2));
                }
            }
            $rows .= '<row r="' . $r . '">' . $cells . '</row>';
        }
        $cols = '';
        foreach ($widths as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(10, $w)) . '" customWidth="1"/>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>' . $rows . '</sheetData></worksheet>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="3"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="#,##0.000"/><numFmt numFmtId="166" formatCode="0.00&quot; %&quot;"/></numFmts>'
            . '<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF4C1D95"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF77738A"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF7C3AED"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="13">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="4" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="165" fontId="4" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="166" fontId="4" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '<xf numFmtId="1" fontId="4" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

        $name = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $t->title) ?? 'Reporte', 0, 31);
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::x(trim($name) === '' ? 'Reporte' : trim($name)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => $styles,
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'bicx');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal.');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                throw new RuntimeException('No se pudo crear el Excel.');
            }
            foreach ($files as $path => $content) {
                $zip->addFromString($path, $content);
            }
            $zip->close();
            $bin = (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }

        return $bin;
    }
}
