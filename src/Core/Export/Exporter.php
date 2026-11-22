<?php

declare(strict_types=1);

namespace Bic\Core\Export;

use Bic\Core\Csv;
use Bic\Core\Http\Response;

final class Exporter
{
    public const FORMATS = ['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'];

    public static function response(Table $t, string $format, string $baseName, string $footerNote = ''): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName) ?: 'reporte';
        $file = static fn (string $ext): array => ['Content-Disposition' => 'attachment; filename="' . $safe . '.' . $ext . '"'];

        if ($format === 'xlsx') {
            if (!XlsxWriter::available()) {
                return new Response('La exportación a Excel necesita la extensión zip de PHP (actívala en WAMP > PHP > Extensiones > zip).', 501, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }

            return new Response(XlsxWriter::render($t), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] + $file('xlsx'));
        }
        if ($format === 'pdf') {
            return new Response(PdfWriter::render($t, $footerNote), 200, ['Content-Type' => 'application/pdf'] + $file('pdf'));
        }
        $rows = [];
        foreach ($t->allRows() as $row) {
            $rows[] = array_map(static fn (array $c) => $row[$c['key']] ?? '', $t->columns);
        }

        return Csv::response($safe . '.csv', array_map(static fn (array $c): string => $c['label'], $t->columns), $rows);
    }
}
