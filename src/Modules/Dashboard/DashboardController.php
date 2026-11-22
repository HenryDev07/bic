<?php

declare(strict_types=1);

namespace Bic\Modules\Dashboard;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Modules\Expenses\ExpenseService;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Inventory\InventoryService;
use Bic\Modules\Purchases\PurchaseService;
use DateTimeImmutable;
use DateTimeZone;

/** Panel con datos reales. Cada bloque aparece solo si el usuario tiene el permiso del módulo que lo genera. */
final class DashboardController extends Controller
{
    private const PERIODS = [7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días'];

    public function index(Request $request): Response
    {
        $biz = (int) $this->app->actor()['business_id'];
        $db = $this->app->db;
        $audit = $this->app->auditLogger;
        $svc = new DashboardService($db);
        $days = (int) $request->string('days', '30');
        $days = isset(self::PERIODS[$days]) ? $days : 30;

        $nowUtc = $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $today = $this->app->localTime($nowUtc, 'Y-m-d');
        $from = (new DateTimeImmutable($today))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $fromUtc = $this->app->utcBoundary($from);
        $toUtc = $this->app->utcBoundary($today, 1);
        $list = [];
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $today; $d = $d->modify('+1 day')) {
            $list[] = $d->format('Y-m-d');
        }

        $data = ['title' => 'Dashboard', 'periods' => self::PERIODS, 'days' => $days, 'from' => $from, 'to' => $today,
            'business' => $db->first('SELECT name, base_currency, timezone FROM businesses WHERE id = ?', [$biz])];

        if ($this->app->can('sales.view')) {
            $data['sales'] = $svc->salesByDay($biz, $fromUtc, $toUtc, $list, fn (string $utc): string => $this->app->localTime($utc, 'Y-m-d'));
            $data['top'] = $svc->topProducts($biz, $fromUtc, $toUtc);
            $data['open'] = $svc->openOrders($biz);
            $data['receivables'] = $svc->receivables($biz);
            $data['deposits'] = $svc->deposits($biz);
        }
        if ($this->app->can('expenses.view')) {
            $data['pl'] = (new ExpenseService($db, $audit, new FinanceService($db, $audit)))->profitAndLoss($biz, $from, $today, $fromUtc, $toUtc);
        }
        if ($this->app->can('finance.view')) {
            $accounts = (new FinanceService($db, $audit))->accounts($biz);
            $cash = \Bic\Core\Money\Decimal::zero();
            foreach ($accounts as $a) {
                if ((int) $a['is_active'] === 1) {
                    $cash = $cash->add(\Bic\Core\Money\Decimal::from($a['balance'], 2));
                }
            }
            $data['cash'] = $cash;
            $data['accounts'] = count($accounts);
        }
        if ($this->app->can('purchases.view')) {
            $data['payables'] = $svc->payables($biz, new PurchaseService($db, $audit, new InventoryService($db, $audit), new FinanceService($db, $audit)));
        }
        if ($this->app->can('inventory.view')) {
            $data['low'] = $svc->lowStock($biz);
            if ($this->app->can('products.cost')) {
                $data['inventory'] = (new InventoryService($db, $audit))->valuation($biz);
            }
        }
        $data['hasAny'] = count(array_intersect_key($data, array_flip(['sales', 'pl', 'cash', 'payables', 'low']))) > 0;

        return $this->render('dashboard::index', $data);
    }
}
