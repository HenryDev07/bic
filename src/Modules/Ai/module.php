<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Ai\AiController;

return [
    'key'  => 'ai',
    'name' => 'Asistente IA',
    'menu' => [
        ['group' => 'Análisis', 'label' => 'Asistente IA', 'path' => '/ai', 'icon' => 'layers', 'permission' => 'ai.use', 'order' => 595],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/ai', [AiController::class, 'index'], 'ai.use');
        $r->post('/ai', [AiController::class, 'ask'], 'ai.use');
    },
];
