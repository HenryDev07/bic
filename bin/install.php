<?php

declare(strict_types=1);

/**
 * Instalación completa y repetible:
 *   1. crea la base de datos (admin)
 *   2. aplica migraciones y seeds (admin)
 *   3. crea el usuario limitado bic_app y escribe config/local.php
 *   4. aplica los privilegios mínimos por tabla
 *
 * Uso:  php bin/install.php [--rotate-app-password]
 * El administrador (root) se pide al ejecutar o se toma de BIC_DB_ADMIN_USER /
 * BIC_DB_ADMIN_PASSWORD. Nunca se guarda.
 */

use Bic\Core\Cli;
use Bic\Core\Config;
use Bic\Core\Database\Database;
use Bic\Core\Database\Migrator;
use Bic\Core\Database\Provisioner;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$root = dirname(__DIR__);

$rotate = in_array('--rotate-app-password', $argv, true);
$host = (string) $config->get('database.host');
$port = (int) $config->get('database.port');
$dbName = (string) $config->get('database.name');
$appUser = 'bic_app';
$appHost = getenv('BIC_DB_APP_HOST') ?: 'localhost';

Cli::line('BIC — instalación de la base de datos');
[$adminUser, $adminPass] = Cli::adminCredentials();

try {
    $serverAdmin = new Database($host, $port, null, $adminUser, $adminPass);
    $serverAdmin->pdo();
} catch (PDOException $e) {
    Cli::fail('No se pudo conectar como administrador: ' . $e->getMessage());
}
Cli::ok("Conectado a {$host}:{$port} como {$adminUser}");

$provisioner = new Provisioner($serverAdmin);
$provisioner->createDatabase($dbName);
Cli::ok("Base de datos `{$dbName}` lista");

$adminDb = new Database($host, $port, $dbName, $adminUser, $adminPass);
$migrator = new Migrator($adminDb, $root . '/database/migrations', $root . '/database/seeds');
try {
    $applied = $migrator->migrate(fn (string $m) => Cli::line('  ' . $m));
    Cli::ok(count($applied) . ' migración(es) nueva(s)');
    $migrator->seed();
    Cli::ok('Datos iniciales (roles, permisos, configuración) listos');
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
}

// Usuario limitado de la aplicación.
$reuse = !$rotate
    && $config->get('database.user') === $appUser
    && (string) $config->get('database.password') !== '';
$password = $reuse ? (string) $config->get('database.password') : bin2hex(random_bytes(16));
$provisioner->ensureAppUser($appUser, $appHost, $password, resetPassword: !$reuse);
if (!$reuse) {
    Config::writeLocal($root . '/config/local.php', ['database' => [
        'host' => $host, 'port' => $port, 'name' => $dbName, 'user' => $appUser, 'password' => $password,
    ]]);
    Cli::ok('Usuario bic_app creado y config/local.php escrito');
} else {
    Cli::ok('Usuario bic_app existente conservado');
}

$count = $provisioner->applyGrants($dbName, $appUser, $appHost);
Cli::ok("Privilegios mínimos aplicados ({$count} sentencias)");

Cli::line();
Cli::line('Siguiente paso: php bin/db-check.php');
