<?php

declare(strict_types=1);

namespace Bic\Modules\Reports;

use Bic\Core\Controller;
use Bic\Core\Export\Exporter;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Modules\Expenses\ExpenseService;
use Bic\Modules\Finance\FinanceService;
use DateTimeImmutable;
use DateTimeZone;

final class ReportsController extends Controller
{
    private function nowUtc(): string
    {
        return $this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function date(string $v): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v ? $v : '';
    }

    /** @return array<string,array<string,mixed>> reportes que el usuario puede ver */
    private function allowed(): array
    {
        return array_filter(ReportService::REPORTS, fn (array $d): bool => $this->app->can($d['permission']) && (!$d['cost'] || $this->app->can('products.cost')));
    }

    public function index(Request $request): Response
    {
        $today = $this->app->localTime($this->nowUtc(), 'Y-m-d');
        $from = $this->date((string) $request->string('from', '')) ?: substr($today, 0, 8) . '01';
        $to = $this->date((string) $request->string('to', '')) ?: $today;

        return $this->render('reports::index', ['title' => 'Reportes', 'reports' => $this->allowed(), 'from' => $from, 'to' => $to]);
    }

    public function show(Request $request, array $params): Response
    {
        $key = (string) ($params['key'] ?? '');
        $def = ReportService::REPORTS[$key] ?? null;
        if ($def === null) {
            $this->notFound();
        }
        if (!isset($this->allowed()[$key])) {
            $this->forbid();
        }
        $today = $this->app->localTime($this->nowUtc(), 'Y-m-d');
        $from = $this->date((string) $request->string('from', '')) ?: substr($today, 0, 8) . '01';
        $to = $this->date((string) $request->string('to', '')) ?: $today;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $db = $this->app->db;
        $audit = $this->app->auditLogger;
        $svc = new ReportService($db, new ExpenseService($db, $audit, new FinanceService($db, $audit)));
        $biz = (int) $this->app->actor()['business_id'];
        $table = $svc->build($key, $biz, [
            'from' => $from, 'to' => $to, 'fromUtc' => $this->app->utcBoundary($from), 'toUtc' => $this->app->utcBoundary($to, 1),
            'canCost' => $this->app->can('products.cost'), 'business' => (string) $db->value('SELECT name FROM businesses WHERE id = ?', [$biz]),
        ], fn (string $utc): string => $this->app->localTime($utc, 'Y-m-d H:i'));

        $format = (string) $request->string('format', '');
        if (isset(Exporter::FORMATS[$format])) {
            if (!$this->app->can('reports.export')) {
                $this->forbid();
            }
            $a = $this->app->actor();
            $audit->log($biz, (int) $a['id'], 'report.export', 'reports', null, null, ['report' => $key, 'format' => $format, 'from' => $from, 'to' => $to], $a['ip'] ?? null, $a['ua'] ?? null);

            return Exporter::response($table, $format, 'bic_' . $key . '_' . $to, 'BIC · generado ' . $this->app->localTime($this->nowUtc(), 'Y-m-d H:i'));
        }

        return $this->render('reports::show', ['title' => $def['label'], 'key' => $key, 'def' => $def, 'table' => $table, 'from' => $from, 'to' => $to, 'formats' => Exporter::FORMATS]);
    }
}
