<?php

declare(strict_types=1);

namespace Bic\Modules\Suppliers;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;

final class SuppliersController extends Controller
{
    private function repo(): SupplierRepository
    {
        return new SupplierRepository($this->app->db);
    }

    private function service(): SupplierService
    {
        return new SupplierService($this->app->db, $this->repo(), $this->app->auditLogger);
    }

    public function index(Request $request): Response
    {
        $biz = $this->app->actor()['business_id'];
        $q = (string) $request->string('q', '');
        $total = $this->repo()->paginate($biz, $q, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request));

        return $this->render('suppliers::index', [
            'title' => 'Proveedores', 'q' => $q, 'rows' => $this->repo()->paginate($biz, $q, $pager['per_page'], $pager['offset'])['rows'],
            'pager' => $pager, 'pagePath' => '/suppliers', 'pageQuery' => ['q' => $q],
        ]);
    }

    public function create(): Response
    {
        return $this->form(null, ['currency' => 'USD', 'is_active' => 1], []);
    }

    public function store(Request $request): Response
    {
        $in = $this->input($request);
        $r = $this->service()->save($this->app->actor(), null, $in);
        if (!$r['ok']) {
            return $this->form(null, $in, $r['errors'], 422);
        }
        $this->flash('success', 'Proveedor creado.');

        return $this->redirect('/suppliers/' . $r['id']);
    }

    public function show(Request $request, array $params): Response
    {
        $biz = $this->app->actor()['business_id'];
        $s = $this->repo()->find($biz, $this->id($params)) ?? $this->notFound();
        $canCost = $this->app->can('products.cost');

        return $this->render('suppliers::show', [
            'title' => (string) $s['name'], 's' => $s, 'canCost' => $canCost,
            'quotes' => $canCost ? $this->repo()->quotes($biz, (int) $s['id']) : [],
            'products' => $canCost && $this->app->can('suppliers.edit') ? $this->repo()->productOptions($biz) : [],
            'errors' => [], 'values' => [],
        ]);
    }

    public function edit(Request $request, array $params): Response
    {
        $s = $this->repo()->find($this->app->actor()['business_id'], $this->id($params)) ?? $this->notFound();

        return $this->form($s, $s, []);
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
            return $this->form($this->repo()->find($this->app->actor()['business_id'], $id), $in, $r['errors'], 422);
        }
        $this->flash('success', 'Proveedor actualizado.');

        return $this->redirect('/suppliers/' . $id);
    }

    public function quote(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = [];
        foreach (['product_id', 'currency', 'exchange_rate', 'unit_price', 'freight_cost', 'other_cost', 'min_quantity', 'quoted_on', 'valid_until', 'supplier_sku', 'notes'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $r = $this->service()->addQuote($this->app->actor(), $id, $in, $this->app->clock->now()->format('Y-m-d'));
        if ($r['status'] === 404) {
            $this->notFound();
        }
        $r['ok'] ? $this->flash('success', 'Cotización registrada.') : $this->flash('danger', implode(' ', $r['errors']));

        return $this->redirect('/suppliers/' . $id);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $in = [];
        foreach (['name', 'tax_id', 'contact_name', 'email', 'phone', 'country', 'currency', 'payment_terms', 'lead_time_days', 'notes'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $in['is_active'] = $request->input('is_active') !== null;

        return $in;
    }

    /** @param array<string,mixed>|null $editing @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(?array $editing, array $values, array $errors, int $status = 200): Response
    {
        return $this->render('suppliers::form', ['title' => $editing === null ? 'Nuevo proveedor' : 'Editar proveedor', 'editing' => $editing, 'values' => $values, 'errors' => $errors], $status);
    }
}
