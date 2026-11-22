<?php

declare(strict_types=1);

/**
 * Restaura un respaldo creado por bin/backup.php. DESTRUCTIVO: reemplaza todas las tablas de la base.
 * Verifica el archivo antes de tocar nada, pide credenciales de administrador y una confirmación escrita.
 * Después reaplica los privilegios de bic_app.
 *
 * Uso:  php bin/restore.php storage/backups/bic_..._YYYYMMDD_HHMMSS.sql.gz
 */

use Bic\Core\Backup\BackupManager;
use Bic\Core\Backup\SqlDumper;
use Bic\Core\Cli;
use Bic\Core\Database\Database;
use Bic\Core\Database\Provisioner;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$file = $argv[1] ?? '';
if ($file === '') {
    Cli::fail('Indica el archivo: php bin/restore.php storage/backups/<archivo>.sql.gz');
}

try {
    Cli::line('Verificando el respaldo...');
    (new BackupManager(dirname($file)))->verify($file);
    Cli::ok('Respaldo íntegro');

    $dbName = (string) $config->get('database.name');
    Cli::line("ATENCIÓN: se reemplazarán TODOS los datos de la base «{$dbName}» por los del respaldo.");
    if (Cli::prompt("Escribe el nombre de la base ({$dbName}) para confirmar") !== $dbName) {
        Cli::fail('Confirmación incorrecta. No se cambió nada.');
    }
    [$adminUser, $adminPass] = Cli::adminCredentials();
    $admin = new Database((string) $config->get('database.host'), (int) $config->get('database.port'), $dbName, $adminUser, $adminPass);

    $h = gzopen($file, 'rb');
    $n = 0;
    foreach (SqlDumper::statements($h) as $sql) {
        $admin->run($sql);
        $n++;
    }
    gzclose($h);
    Cli::ok("Restauradas {$n} sentencias");

    $appUser = (string) ($config->get('database.user') ?: 'bic_app');
    $appHost = getenv('BIC_DB_APP_HOST') ?: 'localhost';
    $prov = new Provisioner($admin);
    if ($prov->userExists($appUser, $appHost)) {
        $prov->applyGrants($dbName, $appUser, $appHost);
        Cli::ok("Privilegios de {$appUser} reaplicados");
    }
    Cli::line('Siguiente paso: php bin/doctor.php');
} catch (Throwable $e) {
    Cli::fail('Restauración fallida: ' . $e->getMessage());
}
