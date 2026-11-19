<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Dashboard\DashboardController;

return [
    'key'  => 'dashboard',
    'name' => 'Dashboard',
    'menu' => [
        ['group' => 'General', 'label' => 'Dashboard', 'path' => '/', 'icon' => 'home', 'permission' => 'dashboard.view', 'order' => 10],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/', [DashboardController::class, 'index'], 'dashboard.view');
    },
];
