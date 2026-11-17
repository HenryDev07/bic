<?php

declare(strict_types=1);

/**
 * Arranque común (web y CLI). Devuelve la configuración cargada.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    $msg = "Falta la carpeta vendor/. Ejecuta primero: composer install\n";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $msg);
    } else {
        http_response_code(500);
        echo nl2br($msg);
    }
    exit(1);
}

require $autoload;

return \Bic\Core\Config::load(dirname(__DIR__));
