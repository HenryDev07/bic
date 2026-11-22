<?php

declare(strict_types=1);

namespace Bic\Modules\Insights;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use DateTimeImmutable;
use DateTimeZone;

final class InsightsController extends Controller
{
    private const PERIODS = [30 => 'Últimos 30 días', 90 => 'Últimos 90 días', 180 => 'Últimos 180 días'];

    public function index(Request $request): Response
    {
        $biz = (int) $this->app->actor()['business_id'];
        $svc = new InsightService($this->app->db);
        $days = (int) $request->string('days', '90');
        $days = isset(self::PERIODS[$days]) ? $days : 90;
        $nowUtc = $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $today = $this->app->localTime($nowUtc, 'Y-m-d');
        $from = (new DateTimeImmutable($today))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $prevFrom = (new DateTimeImmutable($from))->modify('-' . $days . ' days')->format('Y-m-d');
        $fromUtc = $this->app->utcBoundary($from);
        $toUtc = $this->app->utcBoundary($today, 1);
        $settings = $this->app->settings;
        $slow = $settings->int($biz, 'insight_slow_days');
        $minMargin = $settings->int($biz, 'insight_min_margin_pct');
        $canCost = $this->app->can('products.cost');

        $data = ['title' => 'Inteligencia comercial', 'periods' => self::PERIODS, 'days' => $days, 'from' => $from, 'to' => $today, 'minMargin' => $minMargin, 'slowDays' => $slow, 'canCost' => $canCost];
        if ($this->app->can('sales.view')) {
            $products = $svc->products($biz, $fromUtc, $toUtc);
            $data['trend'] = $svc->trend($biz, $fromUtc, $toUtc, $this->app->utcBoundary($prevFrom));
            $data['abc'] = $products;
            $data['customers'] = $svc->topCustomers($biz, $fromUtc, $toUtc);
            if ($canCost) {
                $data['lowMargin'] = $svc->lowMargin($products, $minMargin);
            }
        }
        if ($this->app->can('inventory.view')) {
            $data['reorder'] = $svc->reorder($biz, $fromUtc, $toUtc, $days);
            $data['slow'] = $svc->slowMovers($biz, (new DateTimeImmutable($nowUtc, new DateTimeZone('UTC')))->modify('-' . $slow . ' days')->format('Y-m-d H:i:s'), $canCost);
            if ($canCost) {
                $data['belowCost'] = $svc->belowCost($biz);
            }
        }

        return $this->render('insights::index', $data);
    }
}
