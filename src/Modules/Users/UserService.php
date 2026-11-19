<?php

declare(strict_types=1);

namespace Bic\Modules\Users;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Auth\PasswordPolicy;
use Bic\Core\Auth\RoleRepository;
use Bic\Core\Clock;
use Bic\Core\Database\Database;
use Bic\Core\Settings\SettingsService;
use Bic\Core\Validation\Validator;

/**
 * Reglas de negocio de usuarios. Salvaguardas:
 *  - no se puede otorgar un rol con permisos que el actor no tiene (anti-escalada);
 *  - no se puede editar a un usuario con más permisos que el actor;
 *  - no se puede desactivar la propia cuenta;
 *  - siempre debe quedar al menos un administrador activo.
 */
final class UserService
{
    private const LABELS = ['name' => 'Nombre', 'email' => 'Correo', 'password' => 'Contraseña'];

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Roles con indicador de si el actor puede asignarlos.
     *
     * @param array<string,mixed> $actor
     * @return list<array<string,mixed>>
     */
    public function assignableRoles(array $actor): array
    {
        $map = $this->roles->permissionMap();
        $list = [];
        foreach ($this->roles->all($actor['business_id']) as $role) {
            $role['assignable'] = array_diff($map[(int) $role['id']] ?? [], $actor['permissions']) === [];
            $list[] = $role;
        }

        return $list;
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in name, email, password, roles(list<int>)
     * @return array{ok:bool,errors:array<string,string>,id:?int}
     */
    public function create(array $actor, array $in): array
    {
        $businessId = $actor['business_id'];
        $errors = Validator::validate($in, [
            'name' => 'required|max:150', 'email' => 'required|email|max:190', 'password' => 'required',
        ], self::LABELS);
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));

        if (!isset($errors['email']) && $this->users->emailTaken($email, null)) {
            $errors['email'] = 'Ya existe un usuario con ese correo.';
        }
        if (!isset($errors['password'])) {
            $policy = PasswordPolicy::check((string) $in['password'], $email, $this->settings->int($businessId, 'password_min_length'));
            if ($policy !== null) {
                $errors['password'] = $policy;
            }
        }
        $roleIds = $this->checkRoles($in['roles'] ?? [], $actor, $errors);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null];
        }

        $id = $this->db->transaction(function () use ($actor, $in, $email, $roleIds, $businessId): int {
            $id = $this->users->insert(
                $businessId, trim((string) $in['name']), $email, PasswordPolicy::hash((string) $in['password']),
                !empty($in['is_active']), $this->clock->sql()
            );
            $this->users->setRoles($id, $roleIds);
            $this->audit->log($businessId, $actor['id'], 'user.create', 'user', $id, null, [
                'name' => trim((string) $in['name']), 'email' => $email, 'active' => !empty($in['is_active']), 'roles' => $roleIds,
            ], $actor['ip'], $actor['ua']);

            return $id;
        });

        return ['ok' => true, 'errors' => [], 'id' => $id];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in name, email, password (opcional), roles, is_active
     * @return array{ok:bool,errors:array<string,string>,status:?int} status 403/404 cuando corresponde
     */
    public function update(array $actor, int $id, array $in): array
    {
        $businessId = $actor['business_id'];
        $target = $this->users->find($businessId, $id);
        if ($target === null) {
            return ['ok' => false, 'errors' => [], 'status' => 404];
        }
        $currentRoles = $this->users->roleIds($id);
        if (array_diff($this->roles->permissionsOfRoles($currentRoles), $actor['permissions']) !== []) {
            return ['ok' => false, 'errors' => [], 'status' => 403];
        }

        $errors = Validator::validate($in, ['name' => 'required|max:150', 'email' => 'required|email|max:190'], self::LABELS);
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        if (!isset($errors['email']) && $this->users->emailTaken($email, $id)) {
            $errors['email'] = 'Ya existe un usuario con ese correo.';
        }
        $newPassword = (string) ($in['password'] ?? '');
        if ($newPassword !== '') {
            $policy = PasswordPolicy::check($newPassword, $email, $this->settings->int($businessId, 'password_min_length'));
            if ($policy !== null) {
                $errors['password'] = $policy;
            }
        }

        $active = !empty($in['is_active']);
        if ($actor['id'] === $id && !$active) {
            $errors['is_active'] = 'No puedes desactivar tu propia cuenta.';
        }
        $roleIds = $this->checkRoles($in['roles'] ?? [], $actor, $errors);

        $adminRole = $this->roles->idByCode('admin');
        $wasAdmin = (int) $target['is_active'] === 1 && in_array($adminRole, $currentRoles, true);
        $willBeAdmin = $active && in_array($adminRole, $roleIds, true);
        if ($errors === [] && $wasAdmin && !$willBeAdmin && $this->users->countActiveAdmins($businessId, $id) === 0) {
            $errors['roles'] = 'Debe quedar al menos un administrador activo.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'status' => null];
        }

        $this->db->transaction(function () use ($actor, $id, $in, $email, $active, $roleIds, $currentRoles, $target, $newPassword, $businessId): void {
            $fields = ['name' => trim((string) $in['name']), 'email' => $email, 'is_active' => $active ? 1 : 0];
            if ($newPassword !== '') {
                $fields['password_hash'] = PasswordPolicy::hash($newPassword);
                $fields['password_changed_at'] = $this->clock->sql();
            }
            $this->users->update($id, $fields);
            $this->users->setRoles($id, $roleIds);

            $this->audit->log($businessId, $actor['id'], 'user.update', 'user', $id,
                ['name' => $target['name'], 'email' => $target['email'], 'active' => (int) $target['is_active'] === 1, 'roles' => $currentRoles],
                ['name' => $fields['name'], 'email' => $email, 'active' => $active, 'roles' => $roleIds, 'credentials_changed' => $newPassword !== ''],
                $actor['ip'], $actor['ua']);
        });

        return ['ok' => true, 'errors' => [], 'status' => null];
    }

    /**
     * Valida los roles pedidos: existen, hay al menos uno y el actor puede otorgarlos.
     *
     * @param array<string,string> $errors
     * @return list<int>
     */
    private function checkRoles(mixed $requested, array $actor, array &$errors): array
    {
        $ids = is_array($requested) ? array_values(array_unique(array_map('intval', $requested))) : [];
        $valid = $this->roles->existingIds($ids);

        if ($valid === [] || count($valid) !== count($ids)) {
            $errors['roles'] = 'Asigna al menos un rol válido.';

            return [];
        }
        if (array_diff($this->roles->permissionsOfRoles($valid), $actor['permissions']) !== []) {
            $errors['roles'] = 'No puedes asignar roles con permisos que tú no tienes.';

            return [];
        }

        return $valid;
    }
}
