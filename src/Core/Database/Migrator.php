<?php

declare(strict_types=1);

namespace Bic\Core\Database;

use RuntimeException;
use Throwable;

/**
 * Las migraciones (database/migrations/NNNN_nombre.sql) son la ÚNICA fuente de
 * evolución del esquema. Una migración aplicada no se edita: si su contenido
 * cambia, el migrador se detiene. Los cambios nuevos van en una migración nueva.
 *
 * MySQL confirma implícitamente cada DDL, así que no hay rollback de una
 * migración a medias: por eso son pequeñas y usan CREATE TABLE IF NOT EXISTS.
 */
final class Migrator
{
    public const TRACKING_DDL = <<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(100) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_migrations_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsDir,
        private readonly string $seedsDir,
    ) {
    }

    /** Normaliza saltos de línea para que el checksum no dependa de Git/Windows. */
    public static function normalize(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    public static function checksum(string $content): string
    {
        return hash('sha256', self::normalize($content));
    }

    /** @return array<string,string> versión => ruta, ordenado */
    public static function listFiles(string $dir): array
    {
        $files = glob(rtrim($dir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $result = [];
        foreach ($files as $path) {
            $name = basename($path, '.sql');
            if (!preg_match('/^\d{4}_[a-z0-9_]+$/', $name)) {
                throw new RuntimeException("Nombre de migración inválido: {$name}.sql (formato 0001_descripcion.sql)");
            }
            $result[$name] = $path;
        }

        return $result;
    }

    public function ensureTrackingTable(): void
    {
        $this->db->run(self::TRACKING_DDL);
    }

    /** @return array<string,string> versión => checksum aplicado */
    public function applied(): array
    {
        // Solo lectura: lo usa también bic_app (sin privilegio CREATE). La tabla de
        // control la crea ensureTrackingTable(), que llaman migrate() y el CLI de admin.
        $map = [];
        foreach ($this->db->select('SELECT version, checksum FROM schema_migrations ORDER BY id') as $row) {
            $map[(string) $row['version']] = (string) $row['checksum'];
        }

        return $map;
    }

    /** @return list<array{version:string,state:string}> state: applied|pending|modified */
    public function status(): array
    {
        $applied = $this->applied();
        $rows = [];
        foreach (self::listFiles($this->migrationsDir) as $version => $path) {
            $state = 'pending';
            if (isset($applied[$version])) {
                $state = $applied[$version] === self::checksum((string) file_get_contents($path)) ? 'applied' : 'modified';
            }
            $rows[] = ['version' => $version, 'state' => $state];
        }

        return $rows;
    }

    /**
     * @param callable(string):void|null $log
     * @return list<string> versiones aplicadas en esta ejecución
     */
    public function migrate(?callable $log = null): array
    {
        $log ??= static function (string $m): void {
        };
        $done = [];
        $this->ensureTrackingTable();

        foreach ($this->status() as $row) {
            if ($row['state'] === 'modified') {
                throw new RuntimeException(
                    "La migración {$row['version']} fue modificada después de aplicarse. "
                    . 'No la edites: crea una migración nueva con el cambio.'
                );
            }
        }

        foreach (self::listFiles($this->migrationsDir) as $version => $path) {
            if (isset($this->applied()[$version])) {
                continue;
            }
            $sql = self::normalize((string) file_get_contents($path));
            $start = microtime(true);
            try {
                foreach (SqlSplitter::split($sql) as $statement) {
                    $this->db->run($statement);
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Falló la migración {$version}: " . $e->getMessage(), 0, $e);
            }
            $this->db->execute(
                'INSERT INTO schema_migrations (version, checksum, execution_ms) VALUES (?, ?, ?)',
                [$version, self::checksum($sql), (int) round((microtime(true) - $start) * 1000)]
            );
            $log("Migración aplicada: {$version}");
            $done[] = $version;
        }

        return $done;
    }

    /**
     * Ejecuta los seeds (idempotentes: INSERT IGNORE / WHERE NOT EXISTS) en orden.
     *
     * @param callable(string):void|null $log
     */
    public function seed(?callable $log = null): int
    {
        $log ??= static function (string $m): void {
        };
        $files = glob(rtrim($this->seedsDir, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $path) {
            try {
                foreach (SqlSplitter::split((string) file_get_contents($path)) as $statement) {
                    $this->db->run($statement);
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Falló el seed ' . basename($path) . ': ' . $e->getMessage(), 0, $e);
            }
            $log('Seed ejecutado: ' . basename($path));
        }

        return count($files);
    }
}
