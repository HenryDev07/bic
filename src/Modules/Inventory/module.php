<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Inventory\InventoryController;

return [
    'key'  => 'inventory',
    'name' => 'Inventario',
    'menu' => [
        ['group' => 'Inventario', 'label' => 'Existencias', 'path' => '/inventory', 'icon' => 'layers', 'permission' => 'inventory.view', 'order' => 300],
        ['group' => 'Inventario', 'label' => 'Movimientos', 'path' => '/inventory/movements', 'icon' => 'book', 'permission' => 'inventory.view', 'order' => 310],
        ['group' => 'Inventario', 'label' => 'Ajustes y transferencias', 'path' => '/inventory/adjust', 'icon' => 'plus', 'permission' => 'inventory.adjust', 'order' => 320],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/inventory', [InventoryController::class, 'index'], 'inventory.view');
        $r->get('/inventory/movements', [InventoryController::class, 'movements'], 'inventory.view');
        $r->get('/inventory/adjust', [InventoryController::class, 'adjust'], 'inventory.adjust');
        $r->post('/inventory/adjust', [InventoryController::class, 'store'], 'inventory.adjust');
    },
];
