<?php

declare(strict_types=1);

namespace Bic\Core\Auth;

use Bic\Core\Database\Database;

/** Acceso a roles y permisos (catálogo global de la aplicación). */
final class RoleRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(int $businessId): array
    {
        return $this->db->select(
            'SELECT r.id, r.code, r.name, r.description, r.is_system,
                    (SELECT COUNT(*) FROM user_roles ur JOIN users u ON u.id = ur.user_id
                      WHERE ur.role_id = r.id AND u.business_id = ? AND u.deleted_at IS NULL) AS user_count
             FROM roles r ORDER BY r.id',
            [$businessId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT id, code, name, description, is_system FROM roles WHERE id = ?', [$id]);
    }

    public function idByCode(string $code): ?int
    {
        $id = $this->db->value('SELECT id FROM roles WHERE code = ?', [$code]);

        return $id === null ? null : (int) $id;
    }

    /** @return list<array{id:int,code:string,module:string,action:string,description:?string}> */
    public function allPermissions(): array
    {
        return $this->db->select('SELECT id, code, module, action, description FROM permissions ORDER BY module, id');
    }

    /** @return list<string> */
    public function codesForRole(int $roleId): array
    {
        $rows = $this->db->select(
            'SELECT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ? ORDER BY p.code',
            [$roleId]
        );

        return array_map(static fn (array $r): string => (string) $r['code'], $rows);
    }

    /** @return array<int,list<string>> roleId => códigos de permiso */
    public function permissionMap(): array
    {
        $map = [];
        $rows = $this->db->select(
            'SELECT rp.role_id, p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id ORDER BY p.code'
        );
        foreach ($rows as $row) {
            $map[(int) $row['role_id']][] = (string) $row['code'];
        }

        return $map;
    }

    /**
     * @param list<int> $roleIds
     * @return list<string> unión de permisos de esos roles
     */
    public function permissionsOfRoles(array $roleIds): array
    {
        $map = $this->permissionMap();
        $codes = [];
        foreach ($roleIds as $id) {
            $codes = array_merge($codes, $map[$id] ?? []);
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param list<int> $ids
     * @return list<int> solo los que existen
     */
    public function existingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->select("SELECT id FROM roles WHERE id IN ({$in})", $ids);

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** @param list<int> $permissionIds */
    public function setPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        foreach ($permissionIds as $permissionId) {
            $this->db->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permissionId]);
        }
    }
}
