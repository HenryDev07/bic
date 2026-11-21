<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Purchases\PurchasesController;

return [
    'key'  => 'purchases',
    'name' => 'Compras',
    'menu' => [
        ['group' => 'Compras', 'label' => 'Órdenes de compra', 'path' => '/purchases', 'icon' => 'cart', 'permission' => 'purchases.view', 'order' => 210],
    ],
    'routes' => static function (Router $r): void {
        $c = PurchasesController::class;
        $r->get('/purchases', [$c, 'index'], 'purchases.view');
        $r->get('/purchases/create', [$c, 'create'], 'purchases.create');
        $r->post('/purchases', [$c, 'store'], 'purchases.create');
        $r->get('/purchases/{id}', [$c, 'show'], 'purchases.view');
        $r->get('/purchases/{id}/edit', [$c, 'edit'], 'purchases.create');
        $r->post('/purchases/{id}', [$c, 'update'], 'purchases.create');
        $r->post('/purchases/{id}/lines', [$c, 'addLine'], 'purchases.create');
        $r->post('/purchases/{id}/lines/{lid}/delete', [$c, 'removeLine'], 'purchases.create');
        $r->post('/purchases/{id}/costs', [$c, 'addCost'], 'purchases.create');
        $r->post('/purchases/{id}/costs/{cid}/delete', [$c, 'removeCost'], 'purchases.create');
        $r->post('/purchases/{id}/place', [$c, 'place'], 'purchases.create');
        $r->post('/purchases/{id}/cancel', [$c, 'cancel'], 'purchases.cancel');
        $r->post('/purchases/{id}/receive', [$c, 'receive'], 'purchases.receive');
        $r->post('/purchases/{id}/payments', [$c, 'pay'], 'purchases.pay');
        $r->post('/purchases/{id}/payments/{pid}/void', [$c, 'voidPayment'], 'purchases.pay');
    },
];
