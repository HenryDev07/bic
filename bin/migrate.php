<?php

declare(strict_types=1);

/**
 * Aplica migraciones pendientes y reaplica los privilegios de bic_app
 * (las tablas nuevas no son accesibles para la aplicación hasta este paso).
 *
 * Uso:  php bin/migrate.php [--status] [--seed]
 */

use Bic\Core\Cli;
use Bic\Core\Database\Database;
use Bic\Core\Database\Migrator;
use Bic\Core\Database\Provisioner;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$root = dirname(__DIR__);

$host = (string) $config->get('database.host');
$port = (int) $config->get('database.port');
$dbName = (string) $config->get('database.name');
$appUser = (string) ($config->get('database.user') ?: 'bic_app');
$appHost = getenv('BIC_DB_APP_HOST') ?: 'localhost';

[$adminUser, $adminPass] = Cli::adminCredentials();
$adminDb = new Database($host, $port, $dbName, $adminUser, $adminPass);

try {
    $migrator = new Migrator($adminDb, $root . '/database/migrations', $root . '/database/seeds');

    if (in_array('--status', $argv, true)) {
        $migrator->ensureTrackingTable();
        foreach ($migrator->status() as $row) {
            Cli::line(sprintf('  %-45s %s', $row['version'], $row['state']));
        }
        exit(0);
    }

    $applied = $migrator->migrate(fn (string $m) => Cli::line('  ' . $m));
    Cli::ok(count($applied) . ' migración(es) aplicada(s)');

    if (in_array('--seed', $argv, true)) {
        $migrator->seed(fn (string $m) => Cli::line('  ' . $m));
        Cli::ok('Seeds ejecutados');
    }

    $provisioner = new Provisioner($adminDb);
    if ($provisioner->userExists($appUser, $appHost)) {
        Cli::ok('Privilegios de ' . $appUser . ' actualizados (' . $provisioner->applyGrants($dbName, $appUser, $appHost) . ' sentencias)');
    } else {
        Cli::line("  El usuario {$appUser} no existe todavía: ejecuta php bin/install.php");
    }
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
}
