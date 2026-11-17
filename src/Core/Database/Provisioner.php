<?php

declare(strict_types=1);

namespace Bic\Core\Database;

use InvalidArgumentException;

/**
 * Crea la base de datos y el usuario limitado de la aplicación.
 * Se usa solo desde CLI con credenciales de administrador que NO se guardan.
 *
 * Privilegios de bic_app: solo SELECT/INSERT/UPDATE/DELETE por tabla.
 * Nunca CREATE, ALTER, DROP, INDEX, REFERENCES ni GRANT.
 * Las tablas de bitácora inmutable (audit_logs, stock_movements) solo reciben
 * SELECT e INSERT: la propia base impide editarlas o borrarlas.
 */
final class Provisioner
{
    /** Solo SELECT + INSERT (se aplican únicamente a las que existan). */
    public const APPEND_ONLY = ['audit_logs', 'stock_movements'];

    /** Solo SELECT. */
    public const READ_ONLY = ['schema_migrations'];

    public function __construct(private readonly Database $admin)
    {
    }

    public static function assertIdentifier(string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9_.%-]{1,64}$/', $value)) {
            throw new InvalidArgumentException("Identificador no permitido: {$value}");
        }
    }

    public function createDatabase(string $name): void
    {
        self::assertIdentifier($name);
        $this->admin->run(
            "CREATE DATABASE IF NOT EXISTS `{$name}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    public function userExists(string $user, string $host): bool
    {
        return $this->admin->value(
            'SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?',
            [$user, $host]
        ) > 0;
    }

    public function ensureAppUser(string $user, string $host, string $password, bool $resetPassword): void
    {
        self::assertIdentifier($user);
        self::assertIdentifier($host);
        $account = sprintf("'%s'@'%s'", $user, $host);
        $quoted = $this->admin->pdo()->quote($password);

        $this->admin->run("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$quoted}");
        if ($resetPassword) {
            $this->admin->run("ALTER USER {$account} IDENTIFIED BY {$quoted}");
        }
    }

    /**
     * @param list<string> $tables tablas existentes en la base
     * @return list<string>
     */
    public static function grantStatements(string $db, string $user, string $host, array $tables): array
    {
        self::assertIdentifier($db);
        self::assertIdentifier($user);
        self::assertIdentifier($host);
        $account = sprintf("'%s'@'%s'", $user, $host);

        $statements = ["REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$account}"];
        sort($tables);
        foreach ($tables as $table) {
            self::assertIdentifier($table);
            $privileges = match (true) {
                in_array($table, self::READ_ONLY, true)   => 'SELECT',
                in_array($table, self::APPEND_ONLY, true) => 'SELECT, INSERT',
                default                                   => 'SELECT, INSERT, UPDATE, DELETE',
            };
            $statements[] = "GRANT {$privileges} ON `{$db}`.`{$table}` TO {$account}";
        }

        return $statements;
    }

    /** Reaplica los privilegios sobre las tablas actuales. Devuelve nº de sentencias. */
    public function applyGrants(string $db, string $user, string $host): int
    {
        $rows = $this->admin->select(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
            [$db]
        );
        $tables = array_map(static fn (array $r): string => (string) $r['t'], $rows);

        $statements = self::grantStatements($db, $user, $host, $tables);
        foreach ($statements as $sql) {
            $this->admin->run($sql);
        }
        $this->admin->run('FLUSH PRIVILEGES');

        return count($statements);
    }
}
