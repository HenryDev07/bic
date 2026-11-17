<?php

declare(strict_types=1);

/**
 * Herramientas de esquema (no requieren servidor MySQL):
 *   php bin/schema.php build   Regenera database/schema.sql desde las migraciones
 *   php bin/schema.php check   Falla si schema.sql no coincide con las migraciones
 *   php bin/schema.php lint    Verifica FKs, tipos, DECIMAL, business_id y seeds
 */

use Bic\Core\Cli;
use Bic\Core\Database\SchemaBuilder;
use Bic\Core\Database\SchemaLinter;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
$root = dirname(__DIR__);
$builder = new SchemaBuilder($root . '/database/migrations', (string) $config->get('database.name'));
$schemaFile = $root . '/database/schema.sql';

switch ($argv[1] ?? '') {
    case 'build':
        $builder->write($schemaFile);
        Cli::ok('database/schema.sql regenerado');
        break;

    case 'check':
        if (!$builder->inSync($schemaFile)) {
            Cli::fail('schema.sql NO está sincronizado con las migraciones. Ejecuta: php bin/schema.php build');
        }
        Cli::ok('schema.sql sincronizado con las migraciones');
        break;

    case 'lint':
        $result = (new SchemaLinter())->lint($root . '/database/migrations', $root . '/database/seeds');
        foreach ($result['warnings'] as $w) {
            Cli::line('  [AVISO] ' . $w);
        }
        foreach ($result['errors'] as $e) {
            Cli::line('  [ERROR] ' . $e);
        }
        if ($result['errors'] !== []) {
            exit(1);
        }
        Cli::ok("Esquema válido ({$result['tables']} tablas)");
        break;

    default:
        Cli::fail('Uso: php bin/schema.php build|check|lint');
}
