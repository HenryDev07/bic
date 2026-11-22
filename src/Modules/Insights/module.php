<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Insights\InsightsController;

return [
    'key'  => 'insights',
    'name' => 'Inteligencia',
    'menu' => [
        ['group' => 'Análisis', 'label' => 'Inteligencia', 'path' => '/insights', 'icon' => 'layers', 'permission' => 'insights.view', 'order' => 590],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/insights', [InsightsController::class, 'index'], 'insights.view');
    },
];
