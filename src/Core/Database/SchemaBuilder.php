<?php

declare(strict_types=1);

namespace Bic\Core\Database;

/**
 * Genera database/schema.sql a partir de las migraciones (nunca se edita a mano).
 * Sirve para instalar de golpe desde phpMyAdmin: deja registradas las
 * migraciones con su checksum, así `bin/migrate.php` no las repite.
 * Solo contiene estructura; los datos iniciales salen de `migrate.php --seed`.
 */
final class SchemaBuilder
{
    public function __construct(
        private readonly string $migrationsDir,
        private readonly string $dbName = 'business_intelligence_commerce',
    ) {
    }

    public function build(): string
    {
        $out = "-- ============================================================\n"
            . "-- BIC: esquema consolidado. GENERADO por `php bin/schema.php build`.\n"
            . "-- NO EDITAR: la fuente de verdad son database/migrations/*.sql\n"
            . "-- ============================================================\n\n"
            . "CREATE DATABASE IF NOT EXISTS `{$this->dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
            . "USE `{$this->dbName}`;\n\n"
            . Migrator::TRACKING_DDL . ";\n\n";

        $registered = [];
        foreach (Migrator::listFiles($this->migrationsDir) as $version => $path) {
            $content = trim(Migrator::normalize((string) file_get_contents($path)));
            $out .= "-- ===== {$version} =====\n{$content}\n\n";
            $registered[] = sprintf("('%s', '%s')", $version, Migrator::checksum((string) file_get_contents($path)));
        }

        if ($registered !== []) {
            $out .= "INSERT IGNORE INTO schema_migrations (version, checksum) VALUES\n  "
                . implode(",\n  ", $registered) . ";\n";
        }

        return $out;
    }

    public function write(string $path): void
    {
        file_put_contents($path, $this->build());
    }

    public function inSync(string $path): bool
    {
        return is_file($path) && Migrator::normalize((string) file_get_contents($path)) === $this->build();
    }
}
