<?php

declare(strict_types=1);

namespace Bic\Modules\Finance;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Money\Decimal;
use Bic\Core\Paginator;
use DateTimeImmutable;
use DateTimeZone;

final class FinanceController extends Controller
{
    private function service(): FinanceService
    {
        return new FinanceService($this->app->db, $this->app->auditLogger);
    }

    private function biz(): int
    {
        return (int) $this->app->actor()['business_id'];
    }

    private function nowUtc(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function index(): Response
    {
        $accounts = $this->service()->accounts($this->biz());
        $total = Decimal::zero();
        foreach ($accounts as $a) {
            if ((int) $a['is_active'] === 1) {
                $total = $total->add(Decimal::from($a['balance'], 2));
            }
        }

        return $this->render('finance::index', [
            'title' => 'Cuentas', 'accounts' => $accounts, 'total' => $total->toString(),
            'issues' => $this->service()->reconcile($this->biz()),
            'recent' => $this->service()->ledger($this->biz(), [], 8, 0)['rows'],
        ]);
    }

    public function ledger(Request $request): Response
    {
        $f = [
            'account_id' => (int) $request->string('account_id', '0'), 'direction' => (string) $request->string('direction', ''),
            'source' => (string) $request->string('source', ''), 'from' => '', 'to' => '',
        ];
        $fromDate = $this->date((string) $request->string('from', ''));
        $toDate = $this->date((string) $request->string('to', ''));
        $f['from'] = $fromDate !== '' ? $this->app->utcBoundary($fromDate) : '';
        $f['to'] = $toDate !== '' ? $this->app->utcBoundary($toDate, 1) : '';
        $total = $this->service()->ledger($this->biz(), $f, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request));

        return $this->render('finance::ledger', [
            'title' => 'Libro de caja', 'rows' => $this->service()->ledger($this->biz(), $f, $pager['per_page'], $pager['offset'])['rows'],
            'accounts' => $this->service()->accounts($this->biz()), 'sources' => FinanceService::SOURCES,
            'f' => $f + ['from_date' => $fromDate, 'to_date' => $toDate], 'pager' => $pager, 'pagePath' => '/finance/ledger',
            'pageQuery' => ['account_id' => $f['account_id'] ?: '', 'direction' => $f['direction'], 'source' => $f['source'], 'from' => $fromDate, 'to' => $toDate],
        ]);
    }

    public function createAccount(): Response
    {
        return $this->accountForm(null, ['type' => 'cash', 'opening_balance' => '0', 'is_active' => 1], []);
    }

    public function storeAccount(Request $request): Response
    {
        $in = $this->accountInput($request);
        $r = $this->service()->saveAccount($this->app->actor(), null, $in);
        if (!$r['ok']) {
            return $this->accountForm(null, $in, $r['errors'], 422);
        }
        $this->flash('success', 'Cuenta creada.');

        return $this->redirect('/finance');
    }

    public function editAccount(Request $request, array $params): Response
    {
        $a = $this->service()->account($this->biz(), $this->id($params)) ?? $this->notFound();

        return $this->accountForm($a, $a, []);
    }

    public function updateAccount(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = $this->accountInput($request);
        $r = $this->service()->saveAccount($this->app->actor(), $id, $in);
        if ($r['status'] === 404) {
            $this->notFound();
        }
        if (!$r['ok']) {
            return $this->accountForm($this->service()->account($this->biz(), $id), $in, $r['errors'], 422);
        }
        $this->flash('success', 'Cuenta actualizada.');

        return $this->redirect('/finance');
    }

    public function createMovement(Request $request): Response
    {
        $type = (string) $request->string('type', 'other_income');

        return $this->movementForm(['type' => isset(FinanceService::TYPES[$type]) ? $type : 'other_income', 'occurred_on' => $this->localToday()], []);
    }

    public function storeMovement(Request $request): Response
    {
        $in = [];
        foreach (['type', 'account_id', 'to_account_id', 'amount', 'description', 'partner_name', 'occurred_on'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $date = $this->date($in['occurred_on']);
        $in['occurred_at'] = $date === '' ? '' : $this->occurredAt($date);
        $r = $this->service()->register($this->app->actor(), $in, $this->nowUtc());
        if (!$r['ok']) {
            if ($date === '') {
                $r['errors']['occurred_at'] = 'La fecha no es válida.';
            }

            return $this->movementForm($in, $r['errors'], 422);
        }
        $this->flash('success', 'Movimiento registrado.');

        return $this->redirect('/finance/ledger');
    }

    public function void(Request $request, array $params): Response
    {
        $r = $this->service()->void($this->app->actor(), $this->id($params), (string) $request->string('reason', ''), $this->nowUtc());
        if ($r['status'] === 404) {
            $this->notFound();
        }
        $r['ok'] ? $this->flash('success', 'Movimiento anulado.') : $this->flash('danger', (string) $r['error']);

        return $this->redirect('/finance/ledger');
    }

    private function localToday(): string
    {
        return substr($this->app->localTime($this->nowUtc(), 'Y-m-d H:i'), 0, 10) === '' ? '' : $this->app->localTime($this->nowUtc(), 'Y-m-d');
    }

    private function date(string $v): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
    }

    /** Hoy → ahora; otro día → mediodía local de ese día (siempre dentro del día correcto). */
    private function occurredAt(string $ymd): string
    {
        if ($ymd === $this->localToday()) {
            return $this->nowUtc();
        }
        $noon = (new DateTimeImmutable($this->app->utcBoundary($ymd), new DateTimeZone('UTC')))->modify('+12 hours');

        return $noon->format('Y-m-d H:i:s');
    }

    /** @return array<string,mixed> */
    private function accountInput(Request $request): array
    {
        return ['name' => $request->string('name', ''), 'type' => $request->string('type', 'cash'), 'opening_balance' => $request->string('opening_balance', ''), 'is_active' => $request->input('is_active') !== null];
    }

    /** @param array<string,mixed>|null $editing @param array<string,mixed> $values @param array<string,string> $errors */
    private function accountForm(?array $editing, array $values, array $errors, int $status = 200): Response
    {
        $locked = $editing !== null && (int) $this->app->db->value('SELECT COUNT(*) FROM financial_transactions WHERE account_id = ?', [$editing['id']]) > 0;

        return $this->render('finance::account_form', ['title' => $editing === null ? 'Nueva cuenta' : 'Editar cuenta', 'editing' => $editing, 'values' => $values, 'errors' => $errors, 'locked' => $locked], $status);
    }

    /** @param array<string,mixed> $values @param array<string,string> $errors */
    private function movementForm(array $values, array $errors, int $status = 200): Response
    {
        return $this->render('finance::movement_form', [
            'title' => 'Nuevo movimiento', 'values' => $values, 'errors' => $errors, 'types' => FinanceService::TYPES,
            'accounts' => array_values(array_filter($this->service()->accounts($this->biz()), static fn (array $a): bool => (int) $a['is_active'] === 1)),
        ], $status);
    }
}
