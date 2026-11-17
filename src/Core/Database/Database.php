<?php

declare(strict_types=1);

namespace Bic\Core\Database;

use Bic\Core\Config;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Único punto de acceso a MySQL/MariaDB (PDO, consultas preparadas).
 * La sesión se fija en UTC: las fechas se guardan en UTC y se convierten al mostrar.
 *
 * Nota: con EMULATE_PREPARES desactivado no se puede repetir un mismo
 * marcador con nombre en una consulta; usa nombres distintos.
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $depth = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $name,
        private readonly string $user,
        private readonly string $password,
    ) {
    }

    /** Conexión de la aplicación (usuario bic_app, sin privilegios de estructura). */
    public static function fromConfig(Config $config): self
    {
        $user = (string) $config->get('database.user', '');
        if ($user === '') {
            throw new RuntimeException('Falta config/local.php. Ejecuta: php bin/install.php');
        }

        return new self(
            (string) $config->get('database.host', 'localhost'),
            (int) $config->get('database.port', 3306),
            (string) $config->get('database.name'),
            $user,
            (string) $config->get('database.password', ''),
        );
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;%scharset=utf8mb4',
            $this->host,
            $this->port,
            $this->name !== null ? 'dbname=' . $this->name . ';' : ''
        );

        $pdo = new PDO($dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00', "
            . "sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        return $this->pdo = $pdo;
    }

    /** @return list<array<string,mixed>> */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Devuelve filas afectadas. */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);

        return (int) $this->pdo()->lastInsertId();
    }

    /** Sentencia sin parámetros (DDL, SET, etc.). */
    public function run(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    /**
     * Transacción con anidamiento por savepoints.
     * Si el callback lanza, se revierte solo su nivel y se relanza la excepción.
     *
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $level = $this->depth;

        if ($level === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT bic_sp' . $level);
        }
        $this->depth++;

        try {
            $result = $fn($this);
        } catch (Throwable $e) {
            $this->depth--;
            if ($level === 0) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT bic_sp' . $level);
            }
            throw $e;
        }

        $this->depth--;
        if ($level === 0) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT bic_sp' . $level);
        }

        return $result;
    }
}
