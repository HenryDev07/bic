<?php

declare(strict_types=1);

namespace Bic\Core;

use Bic\Core\Http\Response;

/** CSV para Excel (UTF-8 con BOM, separador coma) con protección contra inyección de fórmulas. */
final class Csv
{
    /** @param list<mixed> $cells */
    public static function row(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $text = (string) $cell;
            if ($text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($text)) {
                $text = "'" . $text;
            }
            $out[] = '"' . str_replace('"', '""', $text) . '"';
        }

        return implode(',', $out) . "\r\n";
    }

    /** @param list<string> $header @param list<list<mixed>> $rows */
    public static function response(string $filename, array $header, array $rows): Response
    {
        $body = "\xEF\xBB\xBF" . self::row($header);
        foreach ($rows as $row) {
            $body .= self::row($row);
        }

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"',
        ]);
    }
}
