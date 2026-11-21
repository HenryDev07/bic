<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Sales\SalesController;

return [
    'key'  => 'sales',
    'name' => 'Ventas',
    'menu' => [
        ['group' => 'Ventas', 'label' => 'Pedidos', 'path' => '/sales', 'icon' => 'cart', 'permission' => 'sales.view', 'order' => 300],
    ],
    'routes' => static function (Router $r): void {
        $c = SalesController::class;
        $r->get('/sales', [$c, 'index'], 'sales.view');
        $r->get('/sales/create', [$c, 'create'], 'sales.create');
        $r->post('/sales', [$c, 'store'], 'sales.create');
        $r->post('/sales/reserve-setting', [$c, 'reserveSetting'], 'settings.edit');
        $r->get('/sales/{id}', [$c, 'show'], 'sales.view');
        $r->get('/sales/{id}/edit', [$c, 'edit'], 'sales.create');
        $r->post('/sales/{id}', [$c, 'update'], 'sales.create');
        $r->post('/sales/{id}/lines', [$c, 'addLine'], 'sales.create');
        $r->post('/sales/{id}/lines/{line}/delete', [$c, 'removeLine'], 'sales.create');
        $r->post('/sales/{id}/submit', [$c, 'submit'], 'sales.create');
        $r->post('/sales/{id}/confirm', [$c, 'confirm'], 'sales.create');
        $r->post('/sales/{id}/prepare', [$c, 'prepare'], 'sales.edit');
        $r->post('/sales/{id}/deliver', [$c, 'deliver'], 'sales.edit');
        $r->post('/sales/{id}/cancel', [$c, 'cancel'], 'sales.cancel');
        $r->post('/sales/{id}/pay', [$c, 'pay'], 'sales.pay');
        $r->post('/sales/{id}/refund', [$c, 'refund'], 'sales.refund');
        $r->post('/sales/{id}/payments/{payment}/void', [$c, 'voidPayment'], 'sales.refund');
        $r->post('/sales/{id}/return', [$c, 'createReturn'], 'sales.return');
    },
];
