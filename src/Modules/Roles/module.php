<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Roles\RolesController;

return [
    'key'  => 'roles',
    'name' => 'Roles',
    'menu' => [
        ['group' => 'Administración', 'label' => 'Roles y permisos', 'path' => '/roles', 'icon' => 'shield', 'permission' => 'roles.view', 'order' => 920],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/roles', [RolesController::class, 'index'], 'roles.view');
        $r->get('/roles/{id}', [RolesController::class, 'edit'], 'roles.view');
        $r->post('/roles/{id}', [RolesController::class, 'update'], 'roles.edit');
    },
];
