<?php

declare(strict_types=1);

namespace Bic\Modules\Catalog;

use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;

final class CatalogController extends Controller
{
    private function service(): CatalogService
    {
        return new CatalogService($this->app->db, $this->app->auditLogger);
    }

    /** La entidad es el segmento de la ruta: /catalog/{entity}/... */
    private function entity(array $params): string
    {
        $entity = (string) ($params['entity'] ?? '');

        return CatalogService::exists($entity) ? $entity : $this->notFound();
    }

    public function index(Request $request, array $params = []): Response
    {
        $entity = isset($params['entity']) ? $this->entity($params) : 'categories';

        return $this->render('catalog::index', [
            'title' => 'Catálogo', 'entity' => $entity, 'entities' => CatalogService::ENTITIES,
            'cfg' => CatalogService::ENTITIES[$entity], 'rows' => $this->service()->all($this->app->actor()['business_id'], $entity),
        ]);
    }

    public function create(Request $request, array $params): Response
    {
        $entity = $this->entity($params);

        return $this->form($entity, null, ['is_active' => 1, 'rate' => '0'], []);
    }

    public function store(Request $request, array $params): Response
    {
        $entity = $this->entity($params);
        $input = $this->input($request);
        $result = $this->service()->save($this->app->actor(), $entity, null, $input);
        if (!$result['ok']) {
            return $this->form($entity, null, $input, $result['errors'], 422);
        }
        $this->flash('success', 'Registro creado.');

        return $this->redirect('/catalog/' . $entity);
    }

    public function edit(Request $request, array $params): Response
    {
        $entity = $this->entity($params);
        $row = $this->service()->find($this->app->actor()['business_id'], $entity, $this->id($params)) ?? $this->notFound();

        return $this->form($entity, $row, $row, []);
    }

    public function update(Request $request, array $params): Response
    {
        $entity = $this->entity($params);
        $id = $this->id($params);
        $input = $this->input($request);
        $result = $this->service()->save($this->app->actor(), $entity, $id, $input);
        if ($result['status'] === 404) {
            $this->notFound();
        }
        if (!$result['ok']) {
            return $this->form($entity, $this->service()->find($this->app->actor()['business_id'], $entity, $id), $input, $result['errors'], 422);
        }
        $this->flash('success', 'Cambios guardados.');

        return $this->redirect('/catalog/' . $entity);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        return [
            'name' => $request->string('name', ''), 'code' => $request->string('code', ''), 'rate' => $request->string('rate', ''),
            'is_default' => $request->input('is_default') !== null, 'is_active' => $request->input('is_active') !== null,
        ];
    }

    /** @param array<string,mixed>|null $editing @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(string $entity, ?array $editing, array $values, array $errors, int $status = 200): Response
    {
        $cfg = CatalogService::ENTITIES[$entity];

        return $this->render('catalog::form', [
            'title' => ($editing === null ? 'Nuevo: ' : 'Editar: ') . $cfg['singular'], 'entity' => $entity, 'cfg' => $cfg,
            'editing' => $editing, 'values' => $values, 'errors' => $errors,
        ], $status);
    }
}
