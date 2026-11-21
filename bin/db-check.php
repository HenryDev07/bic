<?php

declare(strict_types=1);

/**
 * Verifica la instalación CON LAS CREDENCIALES DE LA APLICACIÓN (bic_app):
 * conexión, UTC, migraciones y, sobre todo, que los límites de seguridad funcionen
 * (la aplicación NO debe poder alterar el esquema ni editar la auditoría).
 *
 * Uso:  php bin/db-check.php
 */

use Bic\Core\Cli;
use Bic\Core\Database\Database;
use Bic\Core\Database\Migrator;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$root = dirname(__DIR__);
$failures = 0;

$check = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    if (!$pass) {
        $failures++;
    }
    Cli::line(sprintf('  [%s] %s%s', $pass ? 'OK' : 'FALLA', $label, $detail !== '' ? " — {$detail}" : ''));
};

/** true si la sentencia es rechazada por el servidor (acceso denegado). */
$denied = static function (Database $db, string $sql): bool {
    try {
        $db->run($sql);
    } catch (PDOException $e) {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1142, 1143, 1044, 1227], true);
    }

    return false;
};

Cli::line('BIC — verificación de la base de datos');
Cli::line('  PHP ' . PHP_VERSION . ' · pdo_mysql ' . (extension_loaded('pdo_mysql') ? 'sí' : 'NO') . ' · mbstring ' . (extension_loaded('mbstring') ? 'sí' : 'NO'));

try {
    $db = Database::fromConfig($config);
    $version = (string) $db->value('SELECT VERSION()');
} catch (Throwable $e) {
    Cli::fail('No se pudo conectar con bic_app: ' . $e->getMessage() . ' (¿ejecutaste php bin/install.php?)');
}

$check('Conexión como ' . $config->get('database.user'), true, 'servidor ' . $version);
$check('Sesión en UTC', $db->value('SELECT @@session.time_zone') === '+00:00');
$check('Juego de caracteres utf8mb4', str_starts_with((string) $db->value('SELECT @@character_set_connection'), 'utf8mb4'));

$migrator = new Migrator($db, $root . '/database/migrations', $root . '/database/seeds');
$pending = array_filter($migrator->status(), static fn (array $r): bool => $r['state'] !== 'applied');
$check('Migraciones al día', $pending === [], $pending === [] ? '' : 'pendientes/modificadas: ' . implode(', ', array_column($pending, 'version')));

$check('Negocio inicial presente', (int) $db->value('SELECT COUNT(*) FROM businesses') >= 1);
$check('Roles y permisos sembrados', (int) $db->value('SELECT COUNT(*) FROM roles') >= 4 && (int) $db->value('SELECT COUNT(*) FROM permissions') > 0);

Cli::line('  Límites de seguridad de bic_app (deben ser rechazados):');
$check('No puede crear tablas', $denied($db, 'CREATE TABLE _bic_probe (id INT)'));
$check('No puede alterar tablas', $denied($db, 'ALTER TABLE users ADD COLUMN _probe INT'));
$check('No puede borrar tablas', $denied($db, 'DROP TABLE users'));
$check('No puede editar audit_logs', $denied($db, 'UPDATE audit_logs SET action = action WHERE id = 0'));
$check('No puede borrar de audit_logs', $denied($db, 'DELETE FROM audit_logs WHERE id = 0'));
$check('No puede borrar asientos del libro de caja', $denied($db, 'DELETE FROM financial_transactions WHERE id = 0'));
$check('No puede editar el historial de precios', $denied($db, 'UPDATE product_price_history SET kind = kind WHERE id = 0'));
$check('No puede editar cotizaciones de proveedores', $denied($db, 'UPDATE supplier_prices SET notes = notes WHERE id = 0'));

Cli::line('  Permisos necesarios (deben funcionar):');
try {
    $businessId = (int) $db->value('SELECT MIN(id) FROM businesses');
    $db->transaction(function (Database $d) use ($businessId): void {
        $d->insert('INSERT INTO audit_logs (business_id, action, entity_type) VALUES (?, ?, ?)', [$businessId, 'db_check', 'probe']);
        throw new RuntimeException('rollback-probe');
    });
} catch (RuntimeException $e) {
    $check('Puede insertar en audit_logs (se revierte)', $e->getMessage() === 'rollback-probe');
} catch (Throwable $e) {
    $check('Puede insertar en audit_logs', false, $e->getMessage());
}

Cli::line();
Cli::line($failures === 0 ? 'Todo correcto.' : "Hay {$failures} comprobación(es) fallida(s).");
exit($failures === 0 ? 0 : 1);
