<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Customers\CustomersController;

return [
    'key'  => 'customers',
    'name' => 'Clientes',
    'menu' => [
        ['group' => 'Ventas', 'label' => 'Clientes', 'path' => '/customers', 'icon' => 'users', 'permission' => 'customers.view', 'order' => 310],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/customers', [CustomersController::class, 'index'], 'customers.view');
        $r->get('/customers/create', [CustomersController::class, 'create'], 'customers.create');
        $r->post('/customers', [CustomersController::class, 'store'], 'customers.create');
        $r->get('/customers/{id}', [CustomersController::class, 'show'], 'customers.view');
        $r->get('/customers/{id}/edit', [CustomersController::class, 'edit'], 'customers.edit');
        $r->post('/customers/{id}', [CustomersController::class, 'update'], 'customers.edit');
    },
];
