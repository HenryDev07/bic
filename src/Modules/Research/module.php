<?php

declare(strict_types=1);

use Bic\Core\Http\Router;
use Bic\Modules\Research\ResearchController;

return [
    'key'  => 'research',
    'name' => 'Investigación',
    'menu' => [
        ['group' => 'Catálogo', 'label' => 'Investigación', 'path' => '/research', 'icon' => 'layers', 'permission' => 'research.view', 'order' => 150],
    ],
    'routes' => static function (Router $r): void {
        $c = ResearchController::class;
        $r->get('/research', [$c, 'index'], 'research.view');
        $r->get('/research/create', [$c, 'create'], 'research.manage');
        $r->post('/research', [$c, 'store'], 'research.manage');
        $r->get('/research/{id}', [$c, 'show'], 'research.view');
        $r->get('/research/{id}/edit', [$c, 'edit'], 'research.manage');
        $r->post('/research/{id}', [$c, 'update'], 'research.manage');
        $r->post('/research/{id}/prices', [$c, 'addPrice'], 'research.manage');
    },
];
