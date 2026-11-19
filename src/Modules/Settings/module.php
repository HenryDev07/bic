<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Settings\SettingsController;

return [
    'key'  => 'settings',
    'name' => 'Configuración',
    'menu' => [
        ['group' => 'Administración', 'label' => 'Configuración', 'path' => '/settings', 'icon' => 'settings', 'permission' => 'settings.view', 'order' => 930],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/settings', [SettingsController::class, 'index'], 'settings.view');
        $r->post('/settings', [SettingsController::class, 'update'], 'settings.edit');
    },
];
