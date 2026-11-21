<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Suppliers\SuppliersController;

return [
    'key'  => 'suppliers',
    'name' => 'Proveedores',
    'menu' => [
        ['group' => 'Compras', 'label' => 'Proveedores', 'path' => '/suppliers', 'icon' => 'truck', 'permission' => 'suppliers.view', 'order' => 200],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/suppliers', [SuppliersController::class, 'index'], 'suppliers.view');
        $r->get('/suppliers/create', [SuppliersController::class, 'create'], 'suppliers.create');
        $r->post('/suppliers', [SuppliersController::class, 'store'], 'suppliers.create');
        $r->get('/suppliers/{id}', [SuppliersController::class, 'show'], 'suppliers.view');
        $r->get('/suppliers/{id}/edit', [SuppliersController::class, 'edit'], 'suppliers.edit');
        $r->post('/suppliers/{id}', [SuppliersController::class, 'update'], 'suppliers.edit');
        $r->post('/suppliers/{id}/quotes', [SuppliersController::class, 'quote'], 'suppliers.edit');
    },
];
