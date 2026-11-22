<?php

declare(strict_types=1);

/**
 * Diagnóstico completo de la instalación: PHP y extensiones, carpetas, conexión, migraciones, usuario de base de datos
 * sin privilegios de administrador, conciliaciones de integridad (finanzas, inventario, ventas) y antigüedad del último respaldo.
 * Solo lee: no modifica nada.
 *
 * Uso:  php bin/doctor.php
 */

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Backup\BackupManager;
use Bic\Core\Clock;
use Bic\Core\Database\Database;
use Bic\Core\Database\Migrator;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Inventory\InventoryService;
use Bic\Modules\Sales\SalesService;

$root = dirname(__DIR__);
$config = require $root . '/bootstrap/app.php';
$bad = 0;
$warn = 0;
$ok = static function (string $m): void {
    echo "  [OK]    {$m}\n";
};
$fail = static function (string $m) use (&$bad): void {
    $bad++;
    echo "  [FALLA] {$m}\n";
};
$note = static function (string $m) use (&$warn): void {
    $warn++;
    echo "  [AVISO] {$m}\n";
};

echo "BIC · diagnóstico\n\nEntorno\n";
version_compare(PHP_VERSION, '8.3.0', '>=') ? $ok('PHP ' . PHP_VERSION) : $fail('PHP ' . PHP_VERSION . ' (se requiere 8.3 o superior)');
foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'ctype' => true, 'openssl' => true, 'zlib' => false, 'zip' => false, 'curl' => false, 'iconv' => false] as $ext => $required) {
    if (extension_loaded($ext)) {
        $ok("extensión {$ext}");
    } elseif ($required) {
        $fail("falta la extensión {$ext} (obligatoria)");
    } else {
        $note("falta la extensión {$ext}: " . ['zlib' => 'sin ella no hay respaldos comprimidos', 'zip' => 'sin ella no se exporta a Excel', 'curl' => 'sin ella no funcionan los proveedores de IA externos', 'iconv' => 'sin ella los PDF pierden acentos'][$ext]);
    }
}
foreach (['storage/logs', 'storage/cache', 'storage/uploads', 'storage/backups'] as $d) {
    is_dir("{$root}/{$d}") && is_writable("{$root}/{$d}") ? $ok("{$d} escribible") : $fail("{$d} no existe o no es escribible");
}
is_file($root . '/config/local.php') ? $ok('config/local.php presente') : $fail('falta config/local.php (ejecuta php bin/install.php)');
if ((bool) $config->get('app.debug') && (string) $config->get('app.env') === 'production') {
    $fail('app.debug activo en producción');
}
if (!(bool) $config->get('app.debug')) {
    $ok('modo debug desactivado');
} else {
    $note('app.debug está activo: desactívalo (BIC_DEBUG=0) al usar el sistema con datos reales');
}

echo "\nBase de datos\n";
try {
    $db = Database::fromConfig($config);
    $db->value('SELECT 1');
    $ok('conexión con el usuario de la aplicación (' . $config->get('database.user') . ')');
    if (strtolower((string) $config->get('database.user')) === 'root') {
        $fail('la aplicación usa root: debe usar bic_app');
    }
    $pending = array_filter((new Migrator($db, $root . '/database/migrations', $root . '/database/seeds'))->status(), static fn (array $r): bool => $r['state'] !== 'applied');
    $pending === [] ? $ok('migraciones al día') : $fail(count($pending) . ' migración(es) pendiente(s): php bin/migrate.php --seed');

    echo "\nIntegridad (conciliaciones)\n";
    $audit = new AuditLogger($db, new Clock());
    $finance = new FinanceService($db, $audit);
    $inventory = new InventoryService($db, $audit);
    $sales = new SalesService($db, $audit, $inventory, $finance);
    foreach ($db->select('SELECT id, name FROM businesses') as $b) {
        $issues = array_merge($finance->reconcile((int) $b['id']), $inventory->reconcile((int) $b['id']), $sales->reconcile((int) $b['id']));
        if ($issues === []) {
            $ok("negocio «{$b['name']}»: finanzas, inventario y ventas cuadran");
        } else {
            foreach ($issues as $i) {
                $fail("negocio «{$b['name']}»: {$i}");
            }
        }
    }
} catch (Throwable $e) {
    $fail('base de datos: ' . $e->getMessage());
}

echo "\nRespaldos\n";
$list = (new BackupManager($root . '/storage/backups'))->list();
if ($list === []) {
    $note('no hay respaldos: ejecuta php bin/backup.php y programa uno diario (ver docs/MANUAL_ADMIN.md)');
} else {
    $age = (time() - $list[0]['modified']) / 86400;
    $age <= 2 ? $ok(sprintf('último respaldo hace %.1f día(s): %s', $age, $list[0]['name'])) : $note(sprintf('el último respaldo tiene %.0f días', $age));
}

echo "\nResultado: " . ($bad === 0 ? 'TODO CORRECTO' : "{$bad} problema(s)") . ($warn > 0 ? ", {$warn} aviso(s)" : '') . "\n";
exit($bad === 0 ? 0 : 1);
