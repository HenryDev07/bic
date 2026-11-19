<?php

declare(strict_types=1);

namespace Bic\Modules\Roles;

use Bic\Core\Auth\RoleRepository;
use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;

final class RolesController extends Controller
{
    private function repo(): RoleRepository
    {
        return new RoleRepository($this->app->db);
    }

    public function index(): Response
    {
        $actor = $this->app->actor();
        $map = $this->repo()->permissionMap();
        $roles = array_map(static function (array $r) use ($map): array {
            $r['permission_count'] = count($map[(int) $r['id']] ?? []);

            return $r;
        }, $this->repo()->all($actor['business_id']));

        return $this->render('roles::index', ['title' => 'Roles y permisos', 'roles' => $roles]);
    }

    public function edit(Request $request, array $params): Response
    {
        $role = $this->repo()->find($this->id($params)) ?? $this->notFound();

        return $this->form($role, $this->repo()->codesForRole((int) $role['id']));
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $codes = array_values(array_filter((array) ($request->post['perm'] ?? []), 'is_string'));
        $service = new RoleService($this->app->db, $this->repo(), $this->app->auditLogger);
        $result = $service->updatePermissions($this->app->actor(), $id, $codes);

        if ($result['status'] === 404) {
            $this->notFound();
        }
        if (!$result['ok']) {
            $role = $this->repo()->find($id) ?? $this->notFound();
            $this->flash('danger', (string) $result['error']);

            return $this->form($role, $codes, 422);
        }
        $this->flash('success', 'Permisos actualizados.');

        return $this->redirect('/roles');
    }

    /**
     * @param array<string,mixed> $role
     * @param list<string> $checked
     */
    private function form(array $role, array $checked, int $status = 200): Response
    {
        $grouped = [];
        foreach ($this->repo()->allPermissions() as $p) {
            $grouped[(string) $p['module']][] = $p;
        }
        $actorPerms = $this->app->actor()['permissions'];

        return $this->render('roles::edit', [
            'title' => 'Permisos de ' . $role['name'], 'role' => $role, 'grouped' => $grouped, 'checked' => $checked,
            'moduleNames' => $this->app->modules->names(), 'actorPerms' => $actorPerms,
            'locked' => $role['code'] === 'admin' || !$this->app->can('roles.edit'),
        ], $status);
    }
}
