<?php

declare(strict_types=1);

namespace Bic\Modules\Roles;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Auth\RoleRepository;
use Bic\Core\Database\Database;

/**
 * Edición de permisos por rol. Salvaguardas:
 *  - el rol Administrador es inmutable (siempre tiene todos los permisos);
 *  - solo se pueden OTORGAR permisos que el propio actor posee (anti-escalada).
 */
final class RoleService
{
    public function __construct(
        private readonly Database $db,
        private readonly RoleRepository $roles,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $actor
     * @param list<string> $codes permisos marcados
     * @return array{ok:bool,error:?string,status:?int}
     */
    public function updatePermissions(array $actor, int $roleId, array $codes): array
    {
        $role = $this->roles->find($roleId);
        if ($role === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        if ($role['code'] === 'admin') {
            return ['ok' => false, 'error' => 'El rol Administrador siempre tiene todos los permisos y no se puede modificar.', 'status' => null];
        }

        $catalog = [];
        foreach ($this->roles->allPermissions() as $p) {
            $catalog[(string) $p['code']] = (int) $p['id'];
        }
        $codes = array_values(array_unique($codes));
        if (array_diff($codes, array_keys($catalog)) !== []) {
            return ['ok' => false, 'error' => 'Se enviaron permisos que no existen.', 'status' => null];
        }

        $old = $this->roles->codesForRole($roleId);
        $added = array_values(array_diff($codes, $old));
        $removed = array_values(array_diff($old, $codes));
        $notOwned = array_diff($added, $actor['permissions']);
        if ($notOwned !== []) {
            return ['ok' => false, 'error' => 'No puedes otorgar permisos que tú no tienes: ' . implode(', ', $notOwned) . '.', 'status' => null];
        }
        if ($added === [] && $removed === []) {
            return ['ok' => true, 'error' => null, 'status' => null];
        }

        $this->db->transaction(function () use ($actor, $roleId, $codes, $catalog, $added, $removed): void {
            $this->roles->setPermissions($roleId, array_map(static fn (string $c): int => $catalog[$c], $codes));
            $this->audit->log($actor['business_id'], $actor['id'], 'role.permissions_update', 'role', $roleId,
                ['removed' => $removed], ['added' => $added], $actor['ip'], $actor['ua']);
        });

        return ['ok' => true, 'error' => null, 'status' => null];
    }
}
