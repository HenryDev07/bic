<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Expenses\ExpensesController;

return [
    'key'  => 'expenses',
    'name' => 'Gastos',
    'menu' => [
        ['group' => 'Finanzas', 'label' => 'Gastos', 'path' => '/expenses', 'icon' => 'wallet', 'permission' => 'expenses.view', 'order' => 420],
        ['group' => 'Finanzas', 'label' => 'Estado de resultados', 'path' => '/expenses/profit', 'icon' => 'book', 'permission' => 'expenses.view', 'order' => 430],
    ],
    'routes' => static function (Router $r): void {
        $c = ExpensesController::class;
        $r->get('/expenses', [$c, 'index'], 'expenses.view');
        $r->get('/expenses/profit', [$c, 'profit'], 'expenses.view');
        $r->get('/expenses/create', [$c, 'create'], 'expenses.create');
        $r->post('/expenses', [$c, 'store'], 'expenses.create');
        $r->post('/expenses/categories', [$c, 'addCategory'], 'expenses.create');
        $r->post('/expenses/{id}/void', [$c, 'void'], 'expenses.void');
    },
];
