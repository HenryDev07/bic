<?php

declare(strict_types=1);

namespace Bic\Modules\Users;

use Bic\Core\Auth\RoleRepository;
use Bic\Core\Controller;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Paginator;

final class UsersController extends Controller
{
    private function service(): UserService
    {
        return new UserService(
            $this->app->db, new UserRepository($this->app->db), new RoleRepository($this->app->db),
            $this->app->settings, $this->app->auditLogger, $this->app->clock
        );
    }

    private function repo(): UserRepository
    {
        return new UserRepository($this->app->db);
    }

    public function index(Request $request): Response
    {
        $actor = $this->app->actor();
        $q = (string) $request->string('q', '');
        $status = in_array($request->string('status'), ['active', 'inactive'], true) ? (string) $request->string('status') : '';
        $total = $this->repo()->paginate($actor['business_id'], $q, $status, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request));
        $result = $this->repo()->paginate($actor['business_id'], $q, $status, $pager['per_page'], $pager['offset']);

        return $this->render('users::index', [
            'title' => 'Usuarios', 'rows' => $result['rows'], 'q' => $q, 'status' => $status,
            'pager' => $pager, 'pagePath' => '/users', 'pageQuery' => ['q' => $q, 'status' => $status],
        ]);
    }

    public function create(): Response
    {
        return $this->form('create', ['name' => '', 'email' => '', 'is_active' => true], [], []);
    }

    public function store(Request $request): Response
    {
        $input = $this->input($request);
        $result = $this->service()->create($this->app->actor(), $input);
        if (!$result['ok']) {
            return $this->form('create', $input, $result['errors'], $input['roles'], 422);
        }
        $this->flash('success', 'Usuario creado.');

        return $this->redirect('/users');
    }

    public function edit(Request $request, array $params): Response
    {
        $actor = $this->app->actor();
        $id = $this->id($params);
        $user = $this->repo()->find($actor['business_id'], $id) ?? $this->notFound();
        $values = ['name' => $user['name'], 'email' => $user['email'], 'is_active' => (int) $user['is_active'] === 1];

        return $this->form('edit', $values, [], $this->repo()->roleIds($id), 200, $user);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $input = $this->input($request);
        $result = $this->service()->update($this->app->actor(), $id, $input);

        if ($result['status'] === 404) {
            $this->notFound();
        }
        if ($result['status'] === 403) {
            $this->forbid();
        }
        if (!$result['ok']) {
            $user = $this->repo()->find($this->app->actor()['business_id'], $id);

            return $this->form('edit', $input, $result['errors'], $input['roles'], 422, $user);
        }
        $this->flash('success', 'Usuario actualizado.');

        return $this->redirect('/users');
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        return [
            'name' => (string) $request->string('name', ''),
            'email' => (string) $request->string('email', ''),
            'password' => (string) ($request->post['password'] ?? ''),
            'is_active' => $request->input('is_active') !== null,
            'roles' => $request->intList('roles'),
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,string> $errors
     * @param list<int> $selected
     * @param array<string,mixed>|null $editing
     */
    private function form(string $mode, array $values, array $errors, array $selected, int $status = 200, ?array $editing = null): Response
    {
        $actor = $this->app->actor();

        return $this->render('users::form', [
            'title' => $mode === 'create' ? 'Nuevo usuario' : 'Editar usuario',
            'mode' => $mode, 'values' => $values, 'errors' => $errors, 'selected' => $selected, 'editing' => $editing,
            'roles' => $this->service()->assignableRoles($actor),
            'isSelf' => $editing !== null && (int) $editing['id'] === $actor['id'],
            'minLength' => $this->app->settings->int($actor['business_id'], 'password_min_length'),
        ], $status);
    }
}
