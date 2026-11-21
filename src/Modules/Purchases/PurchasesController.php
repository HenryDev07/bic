<?php

declare(strict_types=1);

namespace Bic\Modules\Purchases;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Inventory\InventoryService;
use DateTimeZone;

final class PurchasesController extends Controller
{
    private function service(): PurchaseService
    {
        $audit = $this->app->auditLogger;

        return new PurchaseService($this->app->db, $audit, new InventoryService($this->app->db, $audit), new FinanceService($this->app->db, $audit));
    }

    private function biz(): int
    {
        return (int) $this->app->actor()['business_id'];
    }

    private function nowUtc(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function today(): string
    {
        return $this->app->localTime($this->nowUtc(), 'Y-m-d');
    }

    /** @param array{ok:bool,error?:?string,errors?:array<string,string>,status:int} $r */
    private function done(array $r, string $okMessage, int $id): Response
    {
        if ($r['status'] === 404) {
            $this->notFound();
        }
        $r['ok'] ? $this->flash('success', $okMessage) : $this->flash('danger', (string) ($r['error'] ?? implode(' ', $r['errors'] ?? [])));

        return $this->redirect('/purchases/' . $id);
    }

    public function index(Request $request): Response
    {
        $q = (string) $request->string('q', '');
        $status = (string) $request->string('status', '');
        $total = $this->service()->paginate($this->biz(), $q, $status, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request));

        return $this->render('purchases::index', [
            'title' => 'Órdenes de compra', 'rows' => $this->service()->paginate($this->biz(), $q, $status, $pager['per_page'], $pager['offset'])['rows'],
            'q' => $q, 'status' => $status, 'statuses' => PurchaseService::STATUSES,
            'pager' => $pager, 'pagePath' => '/purchases', 'pageQuery' => ['q' => $q, 'status' => $status],
        ]);
    }

    public function create(Request $request): Response
    {
        $wh = (new InventoryService($this->app->db, $this->app->auditLogger))->warehouses($this->biz());

        return $this->form(null, ['supplier_id' => $request->string('supplier_id', ''), 'warehouse_id' => (string) ($wh[0]['id'] ?? '')], []);
    }

    public function store(Request $request): Response
    {
        $in = $this->headerInput($request);
        $r = $this->service()->save($this->app->actor(), null, $in);
        if (!$r['ok']) {
            return $this->form(null, $in, $r['errors'], 422);
        }
        $this->flash('success', 'Orden creada. Agrega las líneas y envíala.');

        return $this->redirect('/purchases/' . $r['id']);
    }

    public function edit(Request $request, array $params): Response
    {
        $o = $this->service()->find($this->biz(), $this->id($params)) ?? $this->notFound();

        return $o['status'] === 'draft' ? $this->form($o, $o, []) : $this->redirect('/purchases/' . $o['id']);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = $this->headerInput($request);
        $r = $this->service()->save($this->app->actor(), $id, $in);
        if ($r['status'] === 404) {
            $this->notFound();
        }
        if (!$r['ok']) {
            return $this->form($this->service()->find($this->biz(), $id), $in, $r['errors'], 422);
        }
        $this->flash('success', 'Orden actualizada.');

        return $this->redirect('/purchases/' . $id);
    }

    public function show(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $svc = $this->service();
        $order = $svc->find($this->biz(), $id) ?? $this->notFound();
        $inv = new InventoryService($this->app->db, $this->app->auditLogger);
        $fin = new FinanceService($this->app->db, $this->app->auditLogger);

        return $this->render('purchases::show', [
            'title' => (string) $order['po_number'], 'o' => $order, 'lines' => $svc->lines($this->biz(), $id), 'costLines' => $svc->costLines($this->biz(), $id),
            'receipts' => $svc->receipts($this->biz(), $id), 'payments' => $svc->payments($this->biz(), $id), 'totals' => $svc->totals($order, $this->biz()),
            'statuses' => PurchaseService::STATUSES, 'costKinds' => PurchaseService::COST_KINDS,
            'variants' => $order['status'] === 'draft' ? $inv->variantOptions($this->biz()) : [],
            'accounts' => array_values(array_filter($fin->accounts($this->biz()), static fn (array $a): bool => (int) $a['is_active'] === 1)),
            'today' => $this->today(), 'values' => [],
        ]);
    }

    public function addLine(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = [];
        foreach (['variant_id', 'quantity', 'unit_price', 'tax_rate'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $in['tax_recoverable'] = $request->input('tax_recoverable') !== null;

        return $this->done($this->service()->addLine($this->app->actor(), $id, $in), 'Línea agregada.', $id);
    }

    public function removeLine(Request $request, array $params): Response
    {
        return $this->done($this->service()->removeLine($this->app->actor(), $this->id($params), (int) ($params['lid'] ?? 0)), 'Línea eliminada.', $this->id($params));
    }

    public function addCost(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = ['kind' => $request->string('kind', ''), 'amount' => $request->string('amount', ''), 'description' => $request->string('description', '')];

        return $this->done($this->service()->addCostLine($this->app->actor(), $id, $in), 'Costo de llegada agregado.', $id);
    }

    public function removeCost(Request $request, array $params): Response
    {
        return $this->done($this->service()->removeCostLine($this->app->actor(), $this->id($params), (int) ($params['cid'] ?? 0)), 'Costo eliminado.', $this->id($params));
    }

    public function place(Request $request, array $params): Response
    {
        $id = $this->id($params);

        return $this->done($this->service()->place($this->app->actor(), $id, $this->today()), 'Orden enviada al proveedor.', $id);
    }

    public function cancel(Request $request, array $params): Response
    {
        $id = $this->id($params);

        return $this->done($this->service()->cancel($this->app->actor(), $id, (string) $request->string('reason', '')), 'Orden cancelada.', $id);
    }

    public function receive(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $qty = [];
        foreach ((array) $request->input('qty', []) as $lineId => $v) {
            $qty[(int) $lineId] = is_string($v) ? $v : '';
        }
        $r = $this->service()->receive($this->app->actor(), $id, $qty, (string) $request->string('received_on', $this->today()), (string) $request->string('notes', ''), $this->today(), $this->nowUtc());

        return $this->done($r, 'Recepción registrada: el stock y el costo promedio se actualizaron.', $id);
    }

    public function pay(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = ['account_id' => $request->string('account_id', ''), 'amount' => $request->string('amount', ''), 'notes' => $request->string('notes', '')];

        return $this->done($this->service()->pay($this->app->actor(), $id, $in, $this->nowUtc()), 'Pago registrado.', $id);
    }

    public function voidPayment(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $r = $this->service()->voidPayment($this->app->actor(), $id, (int) ($params['pid'] ?? 0), (string) $request->string('reason', ''), $this->nowUtc());

        return $this->done($r, 'Pago anulado.', $id);
    }

    /** @return array<string,mixed> */
    private function headerInput(Request $request): array
    {
        $in = [];
        foreach (['supplier_id', 'warehouse_id', 'currency', 'exchange_rate', 'expected_on', 'notes'] as $k) {
            $in[$k] = $request->string($k, '');
        }

        return $in;
    }

    /** @param array<string,mixed>|null $editing @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(?array $editing, array $values, array $errors, int $status = 200): Response
    {
        return $this->render('purchases::form', [
            'title' => $editing === null ? 'Nueva orden de compra' : 'Editar ' . $editing['po_number'], 'editing' => $editing, 'values' => $values, 'errors' => $errors,
            'suppliers' => $this->service()->supplierOptions($this->biz()),
            'warehouses' => (new InventoryService($this->app->db, $this->app->auditLogger))->warehouses($this->biz()),
        ], $status);
    }
}
