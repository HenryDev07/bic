<?php

declare(strict_types=1);

use Bic\Core\App;
use Bic\Core\Clock;
use Bic\Core\Database\Database;
use Bic\Core\Http\Request;
use Bic\Core\Session;

/** Único punto de entrada web (document root = esta carpeta). */

$root = dirname(__DIR__);
$config = require $root . '/bootstrap/app.php';

ini_set('display_errors', $config->get('app.debug') ? '1' : '0');

try {
    $request = Request::fromGlobals();
    $session = Session::native($request->isSecure());
    $app = new App($config, Database::fromConfig($config), $session, new Clock(), $root);
    $app->handle($request)->send();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "BIC no pudo iniciar. Verifica config/local.php y que la base de datos esté instalada (php bin/install.php).\n";
    if ($config->get('app.debug')) {
        echo "\n" . $e->getMessage() . "\n";
    }
}
