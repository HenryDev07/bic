<?php

declare(strict_types=1);

namespace Bic\Modules\Inventory;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;
use DateTimeImmutable;
use DateTimeZone;

final class InventoryController extends Controller
{
    private function service(): InventoryService
    {
        return new InventoryService($this->app->db, $this->app->auditLogger);
    }

    private function biz(): int
    {
        return (int) $this->app->actor()['business_id'];
    }

    private function nowUtc(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function index(Request $request): Response
    {
        $q = (string) $request->string('q', '');
        $wh = (int) $request->string('warehouse_id', '0');
        $low = $request->string('low', '') === '1';
        $total = $this->service()->stock($this->biz(), $q, $wh, $low, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request));
        $showCost = $this->app->can('products.cost');

        return $this->render('inventory::index', [
            'title' => 'Existencias', 'rows' => $this->service()->stock($this->biz(), $q, $wh, $low, $pager['per_page'], $pager['offset'])['rows'],
            'q' => $q, 'warehouseId' => $wh, 'low' => $low, 'warehouses' => $this->service()->warehouses($this->biz()), 'showCost' => $showCost,
            'valuation' => $showCost ? $this->service()->valuation($this->biz()) : null, 'issues' => $this->service()->reconcile($this->biz()),
            'pager' => $pager, 'pagePath' => '/inventory', 'pageQuery' => ['q' => $q, 'warehouse_id' => $wh ?: '', 'low' => $low ? '1' : ''],
        ]);
    }

    public function movements(Request $request): Response
    {
        $from = $this->date((string) $request->string('from', ''));
        $to = $this->date((string) $request->string('to', ''));
        $f = [
            'variant_id' => (int) $request->string('variant_id', '0'), 'warehouse_id' => (int) $request->string('warehouse_id', '0'),
            'type' => (string) $request->string('type', ''), 'from' => $from !== '' ? $this->app->utcBoundary($from) : '', 'to' => $to !== '' ? $this->app->utcBoundary($to, 1) : '',
        ];
        $total = $this->service()->movements($this->biz(), $f, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request));

        return $this->render('inventory::movements', [
            'title' => 'Movimientos de stock', 'rows' => $this->service()->movements($this->biz(), $f, $pager['per_page'], $pager['offset'])['rows'],
            'f' => $f + ['from_date' => $from, 'to_date' => $to], 'types' => InventoryService::TYPES, 'warehouses' => $this->service()->warehouses($this->biz()),
            'variants' => $this->service()->variantOptions($this->biz()), 'showCost' => $this->app->can('products.cost'),
            'pager' => $pager, 'pagePath' => '/inventory/movements',
            'pageQuery' => ['variant_id' => $f['variant_id'] ?: '', 'warehouse_id' => $f['warehouse_id'] ?: '', 'type' => $f['type'], 'from' => $from, 'to' => $to],
        ]);
    }

    public function adjust(Request $request): Response
    {
        $type = (string) $request->string('type', 'in');

        return $this->form(['type' => in_array($type, ['in', 'out', 'transfer'], true) ? $type : 'in', 'variant_id' => $request->string('variant_id', '')], []);
    }

    public function store(Request $request): Response
    {
        $in = [];
        foreach (['type', 'warehouse_id', 'to_warehouse_id', 'variant_id', 'quantity', 'unit_cost', 'reason'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $r = $this->service()->adjust($this->app->actor(), $in, $this->nowUtc());
        if (!$r['ok']) {
            return $this->form($in, $r['errors'], 422);
        }
        $this->flash('success', 'Movimiento de stock registrado.');

        return $this->redirect('/inventory/movements');
    }

    private function date(string $v): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
    }

    /** @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(array $values, array $errors, int $status = 200): Response
    {
        return $this->render('inventory::adjust', [
            'title' => 'Ajuste de stock', 'values' => $values, 'errors' => $errors, 'warehouses' => $this->service()->warehouses($this->biz()),
            'variants' => $this->service()->variantOptions($this->biz()), 'showCost' => $this->app->can('products.cost'),
        ], $status);
    }
}
