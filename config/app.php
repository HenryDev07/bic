<?php

declare(strict_types=1);

/**
 * Valores por defecto SIN credenciales ni secretos.
 * Lo específico de cada instalación va en config/local.php (no se versiona).
 * Cualquier clave puede sobrescribirse con variables de entorno (ver Config::ENV_MAP).
 */
return [
    'app' => [
        'name'  => 'Business Intelligence Commerce',
        'env'   => 'local',
        'debug' => true,
        'url'   => 'http://localhost/bic/public',
    ],
    'database' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'business_intelligence_commerce',
        'user'     => '',
        'password' => '',
    ],
    'storage' => [
        // Relativa a la raíz del proyecto, o ruta absoluta en un servidor.
        'path' => 'storage',
    ],
];
