<?php

declare(strict_types=1);

/**
 * Plantilla de configuración local.
 *
 * Normalmente NO hace falta copiarla a mano: `php bin/install.php` genera
 * config/local.php con el usuario `bic_app` y una contraseña aleatoria.
 *
 * Nunca pongas aquí el usuario root. root solo se usa desde la línea de comandos
 * (install/migrate) y se pide en el momento; no se guarda en ningún archivo.
 */
return [
    'database' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'business_intelligence_commerce',
        'user'     => 'bic_app',
        'password' => 'CAMBIAR',
    ],
    // Asistente de IA (opcional). Sin clave funciona en modo local (sin IA externa).
    // 'ai' => [
    //     'provider' => 'anthropic',   // 'anthropic' | 'openai' (también sirve para servidores compatibles con base_url)
    //     'api_key'  => 'TU_CLAVE',    // o variable de entorno BIC_AI_API_KEY
    //     'model'    => '',            // vacío = modelo por defecto del proveedor
    //     'base_url' => '',
    // ],
];
