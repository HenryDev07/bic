<?php

declare(strict_types=1);

namespace Bic\Modules\Sales;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;
use Bic\Modules\Customers\CustomerService;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Inventory\InventoryService;
use DateTimeZone;

final class SalesController extends Controller
{
    private function inventory(): InventoryService
    {
        return new InventoryService($this->app->db, $this->app->auditLogger);
    }

    private function finance(): FinanceService
    {
        return new FinanceService($this->app->db, $this->app->auditLogger);
    }

    private function service(): SalesService
    {
        return new SalesService($this->app->db, $this->app->auditLogger, $this->inventory(), $this->finance());
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

        return $this->redirect('/sales/' . $id);
    }

    public function index(Request $request): Response
    {
        $q = (string) $request->string('q', '');
        $status = (string) $request->string('status', '');
        $pager = Paginator::make($this->service()->paginate($this->biz(), $q, $status, 1, 0)['total'], $this->page($request));

        return $this->render('sales::index', [
            'title' => 'Pedidos', 'q' => $q, 'status' => $status, 'statuses' => SalesService::STATUSES,
            'rows' => $this->service()->paginate($this->biz(), $q, $status, $pager['per_page'], $pager['offset'])['rows'],
            'reserveOn' => $this->service()->reserveOn($this->biz()), 'reserveOptions' => SalesService::RESERVE_OPTIONS,
            'pager' => $pager, 'pagePath' => '/sales', 'pageQuery' => ['q' => $q, 'status' => $status],
        ]);
    }

    public function reserveSetting(Request $request): Response
    {
        $ok = $this->service()->setReserveOn($this->app->actor(), (string) $request->string('reserve_on', ''));
        $ok ? $this->flash('success', 'Configuración de reservas guardada.') : $this->flash('danger', 'Opción no válida.');

        return $this->redirect('/sales');
    }

    public function create(Request $request): Response
    {
        return $this->form(null, ['customer_id' => (string) $request->string('customer_id', ''), 'warehouse_id' => $this->defaultWarehouse()], []);
    }

    public function store(Request $request): Response
    {
        $in = $this->input($request);
        $r = $this->service()->save($this->app->actor(), null, $in);
        if (!$r['ok']) {
            return $this->form(null, $in, $r['errors'], 422);
        }
        $this->flash('success', 'Pedido creado. Agrega las líneas.');

        return $this->redirect('/sales/' . $r['id']);
    }

    public function edit(Request $request, array $params): Response
    {
        $o = $this->service()->find($this->biz(), $this->id($params)) ?? $this->notFound();
        if ($o['status'] !== 'draft') {
            return $this->redirect('/sales/' . $o['id']);
        }

        return $this->form($o, $o, []);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = $this->input($request);
        $r = $this->service()->save($this->app->actor(), $id, $in);
        if ($r['status'] === 404) {
            $this->notFound();
        }
        if (!$r['ok']) {
            return $this->form($this->service()->find($this->biz(), $id), $in, $r['errors'], 422);
        }
        $this->flash('success', 'Pedido actualizado.');

        return $this->redirect('/sales/' . $id);
    }

    public function show(Request $request, array $params): Response
    {
        $svc = $this->service();
        $biz = $this->biz();
        $o = $svc->find($biz, $this->id($params)) ?? $this->notFound();
        $accounts = array_values(array_filter($this->finance()->accounts($biz), static fn (array $a): bool => (int) $a['is_active'] === 1 && $a['currency'] === 'USD'));

        return $this->render('sales::show', [
            'title' => (string) $o['order_number'], 'o' => $o, 'lines' => $svc->lines($biz, (int) $o['id']), 'totals' => $svc->totals($o, $biz),
            'payments' => $svc->payments($biz, (int) $o['id']), 'history' => $svc->history($biz, (int) $o['id']), 'returns' => $svc->returns($biz, (int) $o['id']),
            'statuses' => SalesService::STATUSES, 'accounts' => $accounts, 'today' => $this->today(), 'token' => bin2hex(random_bytes(16)),
            'variants' => $o['status'] === 'draft' ? $svc->variantOptions($biz) : [], 'reserveOn' => $svc->reserveOn($biz),
        ]);
    }

    public function addLine(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = [];
        foreach (['variant_id', 'quantity', 'unit_price', 'tax_rate'] as $k) {
            $in[$k] = $request->string($k, '');
        }

        return $this->done($this->service()->addLine($this->app->actor(), $id, $in), 'Línea agregada.', $id);
    }

    public function removeLine(Request $request, array $params): Response
    {
        $id = $this->id($params);

        return $this->done($this->service()->removeLine($this->app->actor(), $id, (int) $params['line']), 'Línea quitada.', $id);
    }

    public function submit(Request $request, array $params): Response
    {
        return $this->advance($params, 'pending', 'Pedido enviado a pendiente.');
    }

    public function confirm(Request $request, array $params): Response
    {
        return $this->advance($params, 'confirmed', 'Pedido confirmado.');
    }

    public function prepare(Request $request, array $params): Response
    {
        return $this->advance($params, 'prepared', 'Pedido preparado.');
    }

    public function deliver(Request $request, array $params): Response
    {
        return $this->advance($params, 'delivered', 'Pedido entregado: el stock salió y la venta quedó registrada.');
    }

    /** @param array<string,string> $params */
    private function advance(array $params, string $to, string $msg): Response
    {
        $id = $this->id($params);

        return $this->done($this->service()->advance($this->app->actor(), $id, $to, $this->nowUtc()), $msg, $id);
    }

    public function cancel(Request $request, array $params): Response
    {
        $id = $this->id($params);

        return $this->done($this->service()->cancel($this->app->actor(), $id, (string) $request->string('reason', ''), $this->nowUtc()), 'Pedido cancelado.', $id);
    }

    public function pay(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = [];
        foreach (['account_id', 'amount', 'notes', 'token'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $r = $this->service()->pay($this->app->actor(), $id, $in, $this->nowUtc());

        return $this->done($r, !empty($r['duplicate']) ? 'Ese cobro ya estaba registrado (no se duplicó).' : 'Cobro registrado.', $id);
    }

    public function refund(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = [];
        foreach (['account_id', 'amount', 'notes'] as $k) {
            $in[$k] = $request->string($k, '');
        }

        return $this->done($this->service()->refund($this->app->actor(), $id, $in, $this->nowUtc()), 'Reembolso registrado.', $id);
    }

    public function voidPayment(Request $request, array $params): Response
    {
        $id = $this->id($params);

        return $this->done($this->service()->voidPayment($this->app->actor(), $id, (int) $params['payment'], (string) $request->string('reason', ''), $this->nowUtc()), 'Cobro anulado.', $id);
    }

    public function createReturn(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = ['reason' => $request->string('reason', ''), 'returned_on' => $request->string('returned_on', ''), 'restock' => $request->input('restock') !== null, 'qty' => $request->post['qty'] ?? []];

        return $this->done($this->service()->createReturn($this->app->actor(), $id, $in, $this->today(), $this->nowUtc()), 'Devolución registrada.', $id);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        return ['customer_id' => $request->string('customer_id', ''), 'warehouse_id' => $request->string('warehouse_id', ''), 'notes' => $request->string('notes', '')];
    }

    private function defaultWarehouse(): string
    {
        $w = $this->inventory()->warehouses($this->biz());

        return $w === [] ? '' : (string) $w[0]['id'];
    }

    /** @param array<string,mixed>|null $editing @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(?array $editing, array $values, array $errors, int $status = 200): Response
    {
        return $this->render('sales::form', [
            'title' => $editing === null ? 'Nuevo pedido' : 'Editar pedido ' . $editing['order_number'], 'editing' => $editing, 'values' => $values, 'errors' => $errors,
            'customers' => (new CustomerService($this->app->db, $this->app->auditLogger))->options($this->biz()), 'warehouses' => $this->inventory()->warehouses($this->biz()),
        ], $status);
    }
}
