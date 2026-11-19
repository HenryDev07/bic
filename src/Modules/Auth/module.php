<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Auth\AuthController;

return [
    'key'  => 'auth',
    'name' => 'Acceso',
    'menu' => [],
    'routes' => static function (Router $r): void {
        $r->get('/login', [AuthController::class, 'loginForm'], null, true);
        $r->post('/login', [AuthController::class, 'login'], null, true);
        $r->post('/logout', [AuthController::class, 'logout']);
        $r->get('/profile', [AuthController::class, 'profile']);
        $r->post('/profile/password', [AuthController::class, 'changePassword']);
        $r->post('/preferences/theme', [AuthController::class, 'theme']);
    },
];
