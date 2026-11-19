<?php

declare(strict_types=1);

namespace Bic\Tests\Support;

use Bic\Core\Clock;
use Bic\Core\Database\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/** Base de datos SQLite en memoria con migraciones y seeds reales. */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected Database $db;
    protected Clock $clock;

    protected function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Requiere la extensión pdo_sqlite.');
        }
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        SqliteSchema::install($this->pdo, $this->root() . '/database/migrations', $this->root() . '/database/seeds');

        $this->db = Database::fromPdo($this->pdo);
        $this->clock = new Clock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
    }

    protected function businessId(): int
    {
        return (int) $this->db->value('SELECT MIN(id) FROM businesses');
    }

    protected function roleId(string $code): int
    {
        return (int) $this->db->value('SELECT id FROM roles WHERE code = ?', [$code]);
    }

    /** Crea un usuario con un rol del sistema (hash de bajo costo para acelerar las pruebas). */
    protected function makeUser(string $role, ?string $email = null, string $password = 'Secreto12345', bool $active = true): int
    {
        // Igual que UserService: los correos se guardan siempre en minúsculas.
        $email = mb_strtolower($email ?? $role . '-' . bin2hex(random_bytes(3)) . '@ejemplo.com');
        $id = $this->db->insert(
            'INSERT INTO users (business_id, name, email, password_hash, is_active) VALUES (?, ?, ?, ?, ?)',
            [$this->businessId(), ucfirst($role) . ' Prueba', $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]), $active ? 1 : 0]
        );
        $this->db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $this->roleId($role)]);

        return $id;
    }

    protected function emailOf(int $userId): string
    {
        return (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$userId]);
    }

    /** @return list<string> */
    protected function permissionsOf(int $userId): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id WHERE ur.user_id = ? ORDER BY p.code',
            [$userId]
        );

        return array_map(static fn (array $r): string => (string) $r['code'], $rows);
    }

    /** @return array<string,mixed> contexto de actor para los servicios */
    protected function actorFor(int $userId): array
    {
        return [
            'id' => $userId, 'business_id' => $this->businessId(),
            'permissions' => $this->permissionsOf($userId), 'ip' => '127.0.0.1', 'ua' => 'phpunit',
        ];
    }

    protected function countRows(string $table, string $where = '1=1', array $params = []): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
    }
}
