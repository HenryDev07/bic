<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Audit\AuditController;

return [
    'key'  => 'audit',
    'name' => 'Auditoría',
    'menu' => [
        ['group' => 'Administración', 'label' => 'Auditoría', 'path' => '/audit', 'icon' => 'audit', 'permission' => 'audit.view', 'order' => 940],
    ],
    'routes' => static function (Router $r): void {
        $r->get('/audit', [AuditController::class, 'index'], 'audit.view');
    },
];
