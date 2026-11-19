<?php

declare(strict_types=1);

namespace Bic\Modules\Audit;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;

final class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $businessId = $this->app->actor()['business_id'];
        $repo = new AuditRepository($this->app->db);

        $date = static fn (?string $v): ?string => $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
        $fromDate = $date($request->string('from'));
        $toDate = $date($request->string('to'));
        $filters = [
            'action' => (string) $request->string('action', ''),
            'entity_type' => (string) $request->string('entity_type', ''),
            'user_id' => (int) $request->string('user_id', '0'),
            'from' => $fromDate !== null ? $this->app->utcBoundary($fromDate) : null,
            'to' => $toDate !== null ? $this->app->utcBoundary($toDate, 1) : null,
        ];

        $total = $repo->paginate($businessId, $filters, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request), 25);
        $result = $repo->paginate($businessId, $filters, $pager['per_page'], $pager['offset']);

        return $this->render('audit::index', [
            'title' => 'Auditoría', 'rows' => $result['rows'], 'pager' => $pager,
            'pagePath' => '/audit',
            'pageQuery' => ['action' => $filters['action'], 'entity_type' => $filters['entity_type'], 'user_id' => $filters['user_id'] ?: '', 'from' => $fromDate, 'to' => $toDate],
            'filters' => ['action' => $filters['action'], 'entity_type' => $filters['entity_type'], 'user_id' => $filters['user_id'], 'from' => $fromDate, 'to' => $toDate],
            'actions' => $repo->distinct($businessId, 'action'), 'entityTypes' => $repo->distinct($businessId, 'entity_type'),
            'users' => $repo->users($businessId),
        ]);
    }
}
