<?php

declare(strict_types=1);

namespace Bic\Modules\Users;

use Bic\Core\Database\Database;
use Bic\Core\Paginator;

final class UserRepository
{
    private const UPDATABLE = ['name', 'email', 'is_active', 'password_hash', 'password_changed_at'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $businessId, string $q, string $status, int $limit, int $offset): array
    {
        $where = ['u.business_id = ?', 'u.deleted_at IS NULL'];
        $params = [$businessId];
        if ($q !== '') {
            $where[] = "(u.name LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')";
            $like = Paginator::like($q);
            array_push($params, $like, $like);
        }
        if ($status === 'active') {
            $where[] = 'u.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.is_active = 0';
        }
        $w = implode(' AND ', $where);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM users u WHERE {$w}", $params);
        $rows = $this->db->select(
            "SELECT u.id, u.name, u.email, u.is_active, u.last_login_at FROM users u WHERE {$w} ORDER BY u.name, u.id LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        if ($rows !== []) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $names = [];
            foreach ($this->db->select(
                "SELECT ur.user_id, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id IN ({$in}) ORDER BY r.name",
                $ids
            ) as $r) {
                $names[(int) $r['user_id']][] = (string) $r['name'];
            }
            foreach ($rows as &$row) {
                $row['roles'] = $names[(int) $row['id']] ?? [];
            }
            unset($row);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function find(int $businessId, int $id): ?array
    {
        return $this->db->first(
            'SELECT id, name, email, is_active, last_login_at, created_at FROM users WHERE id = ? AND business_id = ? AND deleted_at IS NULL',
            [$id, $businessId]
        );
    }

    public function emailTaken(string $email, ?int $exceptId): bool
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?',
            [$email, $exceptId ?? 0]
        ) > 0;
    }

    public function insert(int $businessId, string $name, string $email, string $hash, bool $active, string $now): int
    {
        return $this->db->insert(
            'INSERT INTO users (business_id, name, email, password_hash, is_active, password_changed_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$businessId, $name, $email, $hash, $active ? 1 : 0, $now]
        );
    }

    /** @param array<string,mixed> $fields solo columnas permitidas */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $params = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, self::UPDATABLE, true)) {
                throw new \InvalidArgumentException("Columna no editable: {$column}");
            }
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }
        if ($sets !== []) {
            $params[] = $id;
            $this->db->execute('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        }
    }

    /** @return list<int> */
    public function roleIds(int $userId): array
    {
        $rows = $this->db->select('SELECT role_id FROM user_roles WHERE user_id = ? ORDER BY role_id', [$userId]);

        return array_map(static fn (array $r): int => (int) $r['role_id'], $rows);
    }

    /** @param list<int> $roleIds */
    public function setRoles(int $userId, array $roleIds): void
    {
        $this->db->execute('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        foreach ($roleIds as $roleId) {
            $this->db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $roleId]);
        }
    }

    public function countActiveAdmins(int $businessId, ?int $excludeUserId): int
    {
        return (int) $this->db->value(
            "SELECT COUNT(DISTINCT u.id) FROM users u
             JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE r.code = 'admin' AND u.is_active = 1 AND u.deleted_at IS NULL AND u.business_id = ? AND u.id <> ?",
            [$businessId, $excludeUserId ?? 0]
        );
    }
}
