<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Products\ProductsController;

return [
    'key'  => 'products',
    'name' => 'Productos',
    'menu' => [
        ['group' => 'Catálogo', 'label' => 'Productos', 'path' => '/products', 'icon' => 'box', 'permission' => 'products.view', 'order' => 110],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/products', [ProductsController::class, 'index'], 'products.view');
        $r->get('/products/export', [ProductsController::class, 'export'], 'products.view');
        $r->get('/products/create', [ProductsController::class, 'create'], 'products.create');
        $r->post('/products', [ProductsController::class, 'store'], 'products.create');
        $r->get('/products/{id}', [ProductsController::class, 'show'], 'products.view');
        $r->get('/products/{id}/edit', [ProductsController::class, 'edit'], 'products.edit');
        $r->post('/products/{id}', [ProductsController::class, 'update'], 'products.edit');
        $r->post('/products/{id}/variants', [ProductsController::class, 'saveVariant'], 'products.edit');
        $r->post('/products/{id}/variants/{vid}', [ProductsController::class, 'saveVariant'], 'products.edit');
    },
];
