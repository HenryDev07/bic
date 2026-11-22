<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Reports\ReportsController;

return [
    'key'  => 'reports',
    'name' => 'Reportes',
    'menu' => [
        ['group' => 'Análisis', 'label' => 'Reportes', 'path' => '/reports', 'icon' => 'book', 'permission' => 'reports.view', 'order' => 600],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/reports', [ReportsController::class, 'index'], 'reports.view');
        $r->get('/reports/{key}', [ReportsController::class, 'show'], 'reports.view');
    },
];
