<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Catalog\CatalogController;

return [
    'key'  => 'catalog',
    'name' => 'Catálogo',
    'menu' => [
        ['group' => 'Catálogo', 'label' => 'Categorías, marcas e impuestos', 'path' => '/catalog', 'icon' => 'tag', 'permission' => 'catalog.view', 'order' => 120],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/catalog', [CatalogController::class, 'index'], 'catalog.view');
        $r->get('/catalog/{entity}', [CatalogController::class, 'index'], 'catalog.view');
        $r->get('/catalog/{entity}/create', [CatalogController::class, 'create'], 'catalog.manage');
        $r->post('/catalog/{entity}', [CatalogController::class, 'store'], 'catalog.manage');
        $r->get('/catalog/{entity}/{id}/edit', [CatalogController::class, 'edit'], 'catalog.manage');
        $r->post('/catalog/{entity}/{id}', [CatalogController::class, 'update'], 'catalog.manage');
    },
];
