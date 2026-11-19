<?php

declare(strict_types=1);

namespace Bic\Tests\Support;

use Bic\Core\Database\Migrator;
use Bic\Core\Database\SqlSplitter;
use PDO;

/**
 * Construye una base SQLite en memoria a partir de las migraciones y seeds REALES
 * (traduciendo la sintaxis específica de MySQL). Permite probar servicios, permisos
 * y flujos HTTP sin servidor MySQL. No sustituye a bin/db-check.php (privilegios,
 * motor real): solo verifica la lógica de la aplicación.
 */
final class SqliteSchema
{
    public static function install(PDO $pdo, string $migrationsDir, string $seedsDir): void
    {
        foreach (Migrator::listFiles($migrationsDir) as $path) {
            foreach (SqlSplitter::split(Migrator::normalize((string) file_get_contents($path))) as $statement) {
                $pdo->exec(self::table($statement));
            }
        }
        self::seed($pdo, $seedsDir);
    }

    public static function seed(PDO $pdo, string $seedsDir): void
    {
        $files = glob($seedsDir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            foreach (SqlSplitter::split((string) file_get_contents($path)) as $statement) {
                $sql = (string) preg_replace('/^INSERT\s+IGNORE\s+INTO/i', 'INSERT OR IGNORE INTO', $statement);
                $pdo->exec((string) preg_replace('/\s+FROM\s+DUAL\b/i', '', $sql));
            }
        }
    }

    public static function table(string $statement): string
    {
        if (!preg_match('/^CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\((.*)\)\s*ENGINE.*$/is', $statement, $m)) {
            throw new \RuntimeException('Sentencia de migración no soportada por el traductor SQLite: ' . substr($statement, 0, 60));
        }

        $out = [];
        $autoId = false;
        foreach (preg_split('/\R/', $m[2]) ?: [] as $line) {
            $line = rtrim(trim($line), ',');
            if ($line === '') {
                continue;
            }
            if (preg_match('/^id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT$/i', $line)) {
                $out[] = 'id INTEGER PRIMARY KEY AUTOINCREMENT';
                $autoId = true;
                continue;
            }
            if ($autoId && preg_match('/^PRIMARY KEY \(id\)$/i', $line)) {
                continue;
            }
            if (preg_match('/^UNIQUE KEY \w+ \((.+)\)$/i', $line, $u)) {
                $out[] = 'UNIQUE (' . $u[1] . ')';
                continue;
            }
            if (preg_match('/^KEY \w+ \(.+\)$/i', $line)) {
                continue;
            }
            $out[] = (string) preg_replace(
                ['/ON UPDATE CURRENT_TIMESTAMP/i', '/\b(?:BIGINT|INT) UNSIGNED\b/i', '/\bTINYINT\(1\)/i', '/\bENUM\([^)]*\)/i', '/\bDATETIME\b/i', '/\bJSON\b/i', '/\bDECIMAL\(\d+,\d+\)/i'],
                ['', 'INTEGER', 'INTEGER', 'TEXT', 'TEXT', 'TEXT', 'NUMERIC'],
                $line
            );
        }

        return "CREATE TABLE {$m[1]} (\n  " . implode(",\n  ", $out) . "\n)";
    }
}
