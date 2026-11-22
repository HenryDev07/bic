<?php

declare(strict_types=1);

namespace Bic\Core\Backup;

use RuntimeException;

/**
 * Respaldo lógico en SQL puro (sin depender de mysqldump, que en WAMP cambia de ruta con cada versión).
 * Formato: cabecera con un separador aleatorio por respaldo; cada sentencia termina con una línea-comentario que contiene ese separador,
 * de modo que ningún dato del negocio puede confundirse con un separador. Termina con una marca de «completo».
 */
final class SqlDumper
{
    public const VERSION = 'BIC-DUMP v1';
    public const FOOTER = '-- BIC-DUMP-COMPLETE';

    /** @param resource $out */
    public static function write(DumpSource $src, $out, string $database, string $createdUtc, ?string $token = null): int
    {
        $token ??= bin2hex(random_bytes(16));
        $put = static function (string $s) use ($out): void {
            if (fwrite($out, $s) === false) {
                throw new RuntimeException('No se pudo escribir el respaldo (¿disco lleno?).');
            }
        };
        $stmt = static fn (string $sql) => $put($sql . "\n/*{$token}*/\n");

        $put('-- ' . self::VERSION . "\n-- token: {$token}\n-- database: {$database}\n-- created_utc: {$createdUtc}\n");
        $stmt('SET NAMES utf8mb4');
        $stmt("SET time_zone = '+00:00'");
        $stmt('SET FOREIGN_KEY_CHECKS = 0');
        $stmt('SET UNIQUE_CHECKS = 0');
        $total = 0;
        foreach ($src->tables() as $table) {
            $q = '`' . str_replace('`', '``', $table) . '`';
            $stmt("DROP TABLE IF EXISTS {$q}");
            $stmt($src->createSql($table));
            $cols = $src->columns($table);
            $names = implode(', ', array_map(static fn (array $c): string => '`' . $c['name'] . '`', $cols));
            foreach ($src->rows($table, 200) as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    $vals = [];
                    foreach ($cols as $c) {
                        $vals[] = self::literal($src, $row[$c['name']] ?? null, $c['numeric']);
                    }
                    $values[] = '(' . implode(', ', $vals) . ')';
                }
                if ($values !== []) {
                    $stmt("INSERT INTO {$q} ({$names}) VALUES\n" . implode(",\n", $values));
                    $total += count($values);
                }
            }
        }
        $stmt('SET FOREIGN_KEY_CHECKS = 1');
        $put(self::FOOTER . " rows={$total}\n");

        return $total;
    }

    public static function literal(DumpSource $src, mixed $v, bool $numeric): string
    {
        if ($v === null) {
            return 'NULL';
        }
        $s = (string) $v;
        if ($numeric && preg_match('/^-?\d+(\.\d+)?$/', $s) === 1) {
            return $s;
        }

        return $src->quote($s);
    }

    /**
     * Lee un respaldo (ya descomprimido) y devuelve sus sentencias.
     *
     * @param resource $in
     * @return \Generator<int,string>
     */
    public static function statements($in): \Generator
    {
        $first = fgets($in);
        if ($first === false || trim($first) !== '-- ' . self::VERSION) {
            throw new RuntimeException('El archivo no es un respaldo de BIC (cabecera inválida).');
        }
        $tokenLine = (string) fgets($in);
        if (!preg_match('/^-- token: ([0-9a-f]{32})$/', trim($tokenLine), $m)) {
            throw new RuntimeException('Respaldo dañado: falta el separador.');
        }
        $marker = '/*' . $m[1] . '*/';
        $buf = '';
        $complete = false;
        while (($line = fgets($in)) !== false) {
            $t = rtrim($line, "\r\n");
            if ($t === $marker) {
                if (trim($buf) !== '') {
                    yield $buf;
                }
                $buf = '';
                continue;
            }
            if ($buf === '' && str_starts_with($t, self::FOOTER)) {
                $complete = true;
                continue;
            }
            if ($buf === '' && (str_starts_with($t, '-- ') || $t === '')) {
                continue; // comentarios de cabecera
            }
            $buf .= $line;
        }
        if (!$complete || trim($buf) !== '') {
            throw new RuntimeException('El respaldo está incompleto o cortado: no se restaura.');
        }
    }
}
