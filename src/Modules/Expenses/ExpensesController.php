<?php

declare(strict_types=1);

namespace Bic\Modules\Expenses;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;
use Bic\Modules\Finance\FinanceService;
use DateTimeImmutable;
use DateTimeZone;

final class ExpensesController extends Controller
{
    private function finance(): FinanceService
    {
        return new FinanceService($this->app->db, $this->app->auditLogger);
    }

    private function service(): ExpenseService
    {
        return new ExpenseService($this->app->db, $this->app->auditLogger, $this->finance());
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

    private function date(string $v): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
    }

    public function index(Request $request): Response
    {
        $f = ['from' => $this->date((string) $request->string('from', '')), 'to' => $this->date((string) $request->string('to', '')), 'category_id' => (int) $request->string('category_id', '')];
        $total = $this->service()->paginate($this->biz(), $f, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request));
        $page = $this->service()->paginate($this->biz(), $f, $pager['per_page'], $pager['offset']);

        return $this->render('expenses::index', [
            'title' => 'Gastos', 'rows' => $page['rows'], 'sum' => $page['sum'], 'f' => $f, 'categories' => $this->service()->categories($this->biz(), false),
            'pager' => $pager, 'pagePath' => '/expenses', 'pageQuery' => $f,
        ]);
    }

    public function create(): Response
    {
        return $this->form(['expense_date' => $this->today()], []);
    }

    public function store(Request $request): Response
    {
        $in = [];
        foreach (['category_id', 'account_id', 'amount', 'expense_date', 'description', 'order_id', 'product_id', 'supplier_id'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $date = $this->date($in['expense_date']);
        $at = $date === '' || $date === $this->today() ? $this->nowUtc() : (new DateTimeImmutable($this->app->utcBoundary($date), new DateTimeZone('UTC')))->modify('+12 hours')->format('Y-m-d H:i:s');
        $r = $this->service()->create($this->app->actor(), $in, $this->today(), $at);
        if (!$r['ok']) {
            return $this->form($in, $r['errors'], 422);
        }
        $this->flash('success', 'Gasto registrado.');

        return $this->redirect('/expenses');
    }

    public function addCategory(Request $request): Response
    {
        $r = $this->service()->addCategory($this->app->actor(), (string) $request->string('name', ''));
        $r['ok'] ? $this->flash('success', 'Categoría creada.') : $this->flash('danger', (string) $r['error']);

        return $this->redirect('/expenses');
    }

    public function void(Request $request, array $params): Response
    {
        $r = $this->service()->void($this->app->actor(), $this->id($params), (string) $request->string('reason', ''), $this->nowUtc());
        if ($r['status'] === 404) {
            $this->notFound();
        }
        $r['ok'] ? $this->flash('success', 'Gasto anulado.') : $this->flash('danger', (string) $r['error']);

        return $this->redirect('/expenses');
    }

    public function profit(Request $request): Response
    {
        $today = $this->today();
        $from = $this->date((string) $request->string('from', '')) ?: substr($today, 0, 8) . '01';
        $to = $this->date((string) $request->string('to', '')) ?: $today;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $pl = $this->service()->profitAndLoss($this->biz(), $from, $to, $this->app->utcBoundary($from), $this->app->utcBoundary($to, 1));

        return $this->render('expenses::profit', ['title' => 'Estado de resultados', 'pl' => $pl, 'from' => $from, 'to' => $to]);
    }

    /** @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(array $values, array $errors, int $status = 200): Response
    {
        $accounts = array_values(array_filter($this->finance()->accounts($this->biz()), static fn (array $a): bool => (int) $a['is_active'] === 1));

        return $this->render('expenses::form', [
            'title' => 'Nuevo gasto', 'values' => $values, 'errors' => $errors, 'categories' => $this->service()->categories($this->biz()), 'accounts' => $accounts,
            'suppliers' => $this->service()->lookups($this->biz(), 'suppliers'), 'products' => $this->service()->lookups($this->biz(), 'products'),
        ], $status);
    }
}
