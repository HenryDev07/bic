<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Users\UsersController;

return [
    'key'  => 'users',
    'name' => 'Usuarios',
    'menu' => [
        ['group' => 'Administración', 'label' => 'Usuarios', 'path' => '/users', 'icon' => 'users', 'permission' => 'users.view', 'order' => 910],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/users', [UsersController::class, 'index'], 'users.view');
        $r->get('/users/create', [UsersController::class, 'create'], 'users.create');
        $r->post('/users', [UsersController::class, 'store'], 'users.create');
        $r->get('/users/{id}/edit', [UsersController::class, 'edit'], 'users.edit');
        $r->post('/users/{id}', [UsersController::class, 'update'], 'users.edit');
    },
];
