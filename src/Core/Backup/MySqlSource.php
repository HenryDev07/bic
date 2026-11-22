<?php

declare(strict_types=1);

namespace Bic\Core\Backup;

use Bic\Core\Database\Database;

final class MySqlSource implements DumpSource
{
    public function __construct(private readonly Database $db)
    {
    }

    public function tables(): array
    {
        return array_map(static fn (array $r): string => (string) array_values($r)[0], $this->db->select('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\''));
    }

    public function createSql(string $table): string
    {
        $row = $this->db->first('SHOW CREATE TABLE ' . $this->ident($table));

        return (string) ($row['Create Table'] ?? '');
    }

    public function columns(string $table): array
    {
        return array_map(static fn (array $c): array => ['name' => (string) $c['Field'], 'numeric' => (bool) preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double)\b/i', (string) $c['Type'])],
            $this->db->select('SHOW COLUMNS FROM ' . $this->ident($table)));
    }

    public function rows(string $table, int $chunk): iterable
    {
        $hasId = array_filter($this->columns($table), static fn (array $c): bool => $c['name'] === 'id') !== [];
        if (!$hasId) {
            yield $this->db->select('SELECT * FROM ' . $this->ident($table));

            return;
        }
        $last = 0;
        while (true) {
            $rows = $this->db->select('SELECT * FROM ' . $this->ident($table) . ' WHERE id > ? ORDER BY id LIMIT ' . (int) $chunk, [$last]);
            if ($rows === []) {
                return;
            }
            $last = (int) end($rows)['id'];
            yield $rows;
        }
    }

    public function quote(string $value): string
    {
        return $this->db->pdo()->quote($value);
    }

    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
