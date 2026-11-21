<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Finance\FinanceController;

return [
    'key'  => 'finance',
    'name' => 'Finanzas',
    'menu' => [
        ['group' => 'Finanzas', 'label' => 'Cuentas', 'path' => '/finance', 'icon' => 'wallet', 'permission' => 'finance.view', 'order' => 400],
        ['group' => 'Finanzas', 'label' => 'Libro de caja', 'path' => '/finance/ledger', 'icon' => 'book', 'permission' => 'finance.view', 'order' => 410],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/finance', [FinanceController::class, 'index'], 'finance.view');
        $r->get('/finance/ledger', [FinanceController::class, 'ledger'], 'finance.view');
        $r->get('/finance/accounts/create', [FinanceController::class, 'createAccount'], 'finance.manage');
        $r->post('/finance/accounts', [FinanceController::class, 'storeAccount'], 'finance.manage');
        $r->get('/finance/accounts/{id}/edit', [FinanceController::class, 'editAccount'], 'finance.manage');
        $r->post('/finance/accounts/{id}', [FinanceController::class, 'updateAccount'], 'finance.manage');
        $r->get('/finance/movements/create', [FinanceController::class, 'createMovement'], 'finance.create');
        $r->post('/finance/movements', [FinanceController::class, 'storeMovement'], 'finance.create');
        $r->post('/finance/ledger/{id}/void', [FinanceController::class, 'void'], 'finance.void');
    },
];
