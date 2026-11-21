<?php

declare(strict_types=1);

namespace Bic\Modules\Research;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;
use DateTimeZone;

final class ResearchController extends Controller
{
    private function service(): ResearchService
    {
        return new ResearchService($this->app->db, $this->app->auditLogger);
    }

    private function biz(): int
    {
        return (int) $this->app->actor()['business_id'];
    }

    private function today(): string
    {
        return $this->app->localTime($this->app->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'Y-m-d');
    }

    public function index(Request $request): Response
    {
        $q = (string) $request->string('q', '');
        $status = (string) $request->string('status', '');
        $pager = Paginator::make($this->service()->paginate($this->biz(), $q, $status, 1, 0)['total'], $this->page($request));

        return $this->render('research::index', [
            'title' => 'Investigación', 'q' => $q, 'status' => $status, 'statuses' => ResearchService::STATUSES,
            'rows' => $this->service()->paginate($this->biz(), $q, $status, $pager['per_page'], $pager['offset'])['rows'],
            'pager' => $pager, 'pagePath' => '/research', 'pageQuery' => ['q' => $q, 'status' => $status],
        ]);
    }

    public function create(): Response
    {
        return $this->form(null, ['status' => 'idea'], []);
    }

    public function store(Request $request): Response
    {
        $in = $this->input($request);
        $r = $this->service()->save($this->app->actor(), null, $in);
        if (!$r['ok']) {
            return $this->form(null, $in, $r['errors'], 422);
        }
        $this->flash('success', 'Investigación creada.');

        return $this->redirect('/research/' . $r['id']);
    }

    public function show(Request $request, array $params): Response
    {
        $item = $this->service()->find($this->biz(), $this->id($params)) ?? $this->notFound();
        $prices = $this->service()->prices($this->biz(), (int) $item['id']);

        return $this->render('research::show', [
            'title' => (string) $item['name'], 'item' => $item, 'prices' => $prices, 'a' => $this->service()->analysis($item, $prices),
            'statuses' => ResearchService::STATUSES, 'today' => $this->today(),
        ]);
    }

    public function edit(Request $request, array $params): Response
    {
        $item = $this->service()->find($this->biz(), $this->id($params)) ?? $this->notFound();

        return $this->form($item, $item, []);
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
        $this->flash('success', 'Investigación actualizada.');

        return $this->redirect('/research/' . $id);
    }

    public function addPrice(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $in = [];
        foreach (['source', 'observed_price', 'observed_on', 'url', 'notes'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $r = $this->service()->addPrice($this->app->actor(), $id, $in, $this->today());
        if ($r['status'] === 404) {
            $this->notFound();
        }
        $r['ok'] ? $this->flash('success', 'Precio registrado.') : $this->flash('danger', implode(' ', $r['errors']));

        return $this->redirect('/research/' . $id);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $in = [];
        foreach (['name', 'status', 'estimated_cost', 'target_price', 'product_id', 'notes'] as $k) {
            $in[$k] = $request->string($k, '');
        }

        return $in;
    }

    /** @param array<string,mixed>|null $editing @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(?array $editing, array $values, array $errors, int $status = 200): Response
    {
        return $this->render('research::form', [
            'title' => $editing === null ? 'Nueva investigación' : 'Editar investigación', 'editing' => $editing, 'values' => $values, 'errors' => $errors,
            'statuses' => ResearchService::STATUSES, 'products' => $this->service()->productOptions($this->biz()),
        ], $status);
    }
}
