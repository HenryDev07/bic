<?php

declare(strict_types=1);

/**
 * Respaldo completo de la base de datos (SQL comprimido + suma SHA-256) en storage/backups.
 * Usa el usuario de la aplicación (solo necesita SELECT). Apto para el Programador de tareas de Windows.
 *
 * Uso:  php bin/backup.php [--keep=14] [--dir=ruta]
 */

use Bic\Core\Backup\BackupManager;
use Bic\Core\Backup\MySqlSource;
use Bic\Core\Cli;
use Bic\Core\Database\Database;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$keep = 14;
$dir = dirname(__DIR__) . '/storage/backups';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--keep=(\d+)$/', $a, $m)) {
        $keep = max(1, (int) $m[1]);
    } elseif (str_starts_with($a, '--dir=')) {
        $dir = substr($a, 6);
    }
}

try {
    $db = Database::fromConfig($config);
    $mgr = new BackupManager($dir);
    $r = $mgr->create(new MySqlSource($db), (string) $config->get('database.name'), new DateTimeImmutable('now', new DateTimeZone('UTC')));
    Cli::ok(sprintf('Respaldo creado: %s (%d filas, %s KB, verificado)', $r['name'], $r['rows'], number_format($r['bytes'] / 1024, 1)));
    $deleted = $mgr->prune($keep);
    if ($deleted !== []) {
        Cli::line('  Depurados ' . count($deleted) . " respaldo(s) antiguos (se conservan {$keep}).");
    }
    Cli::line('  Guarda también una copia FUERA de este equipo (disco externo o nube): un respaldo en el mismo disco no protege de una falla del disco.');
} catch (Throwable $e) {
    Cli::fail('Respaldo fallido: ' . $e->getMessage());
}
