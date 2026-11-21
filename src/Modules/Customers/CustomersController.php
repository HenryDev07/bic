<?php

declare(strict_types=1);

namespace Bic\Modules\Customers;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;

final class CustomersController extends Controller
{
    private function service(): CustomerService
    {
        return new CustomerService($this->app->db, $this->app->auditLogger);
    }

    private function biz(): int
    {
        return (int) $this->app->actor()['business_id'];
    }

    public function index(Request $request): Response
    {
        $q = (string) $request->string('q', '');
        $pager = Paginator::make($this->service()->paginate($this->biz(), $q, 1, 0)['total'], $this->page($request));

        return $this->render('customers::index', [
            'title' => 'Clientes', 'q' => $q, 'rows' => $this->service()->paginate($this->biz(), $q, $pager['per_page'], $pager['offset'])['rows'],
            'pager' => $pager, 'pagePath' => '/customers', 'pageQuery' => ['q' => $q],
        ]);
    }

    public function create(): Response
    {
        return $this->form(null, ['is_active' => 1], []);
    }

    public function store(Request $request): Response
    {
        $in = $this->input($request);
        $r = $this->service()->save($this->app->actor(), null, $in);
        if (!$r['ok']) {
            return $this->form(null, $in, $r['errors'], 422);
        }
        $this->flash('success', 'Cliente creado.');

        return $this->redirect('/customers/' . $r['id']);
    }

    public function show(Request $request, array $params): Response
    {
        $c = $this->service()->find($this->biz(), $this->id($params)) ?? $this->notFound();
        $orders = [];
        if ($this->app->can('sales.view')) {
            $orders = $this->app->db->select(
                'SELECT o.id, o.order_number, o.status, o.created_at,
                        (SELECT COALESCE(SUM(l.line_total), 0) FROM order_lines l WHERE l.order_id = o.id) AS total
                 FROM orders o WHERE o.customer_id = ? AND o.business_id = ? ORDER BY o.id DESC LIMIT 50',
                [$c['id'], $this->biz()]
            );
        }

        return $this->render('customers::show', ['title' => (string) $c['name'], 'c' => $c, 'orders' => $orders]);
    }

    public function edit(Request $request, array $params): Response
    {
        $c = $this->service()->find($this->biz(), $this->id($params)) ?? $this->notFound();

        return $this->form($c, $c, []);
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
        $this->flash('success', 'Cliente actualizado.');

        return $this->redirect('/customers/' . $id);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $in = [];
        foreach (['name', 'tax_id', 'email', 'phone', 'address', 'notes'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $in['is_active'] = $request->input('is_active') !== null;

        return $in;
    }

    /** @param array<string,mixed>|null $editing @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(?array $editing, array $values, array $errors, int $status = 200): Response
    {
        return $this->render('customers::form', ['title' => $editing === null ? 'Nuevo cliente' : 'Editar cliente', 'editing' => $editing, 'values' => $values, 'errors' => $errors], $status);
    }
}
