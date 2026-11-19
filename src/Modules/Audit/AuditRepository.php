<?php

declare(strict_types=1);

namespace Bic\Modules\Audit;

use Bic\Core\Database\Database;

final class AuditRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{action:string,entity_type:string,user_id:int,from:?string,to:?string} $f fechas ya en UTC (to exclusivo)
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function paginate(int $businessId, array $f, int $limit, int $offset): array
    {
        $where = ['a.business_id = ?'];
        $params = [$businessId];
        if ($f['action'] !== '') {
            $where[] = 'a.action = ?';
            $params[] = $f['action'];
        }
        if ($f['entity_type'] !== '') {
            $where[] = 'a.entity_type = ?';
            $params[] = $f['entity_type'];
        }
        if ($f['user_id'] > 0) {
            $where[] = 'a.user_id = ?';
            $params[] = $f['user_id'];
        }
        if ($f['from'] !== null) {
            $where[] = 'a.created_at >= ?';
            $params[] = $f['from'];
        }
        if ($f['to'] !== null) {
            $where[] = 'a.created_at < ?';
            $params[] = $f['to'];
        }
        $w = implode(' AND ', $where);

        $total = (int) $this->db->value("SELECT COUNT(*) FROM audit_logs a WHERE {$w}", $params);
        $rows = $this->db->select(
            "SELECT a.id, a.action, a.entity_type, a.entity_id, a.old_values, a.new_values, a.ip_address, a.created_at, u.name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE {$w} ORDER BY a.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<string> */
    public function distinct(int $businessId, string $column): array
    {
        if (!in_array($column, ['action', 'entity_type'], true)) {
            throw new \InvalidArgumentException('Columna no permitida.');
        }
        $rows = $this->db->select("SELECT DISTINCT {$column} AS v FROM audit_logs WHERE business_id = ? ORDER BY v", [$businessId]);

        return array_map(static fn (array $r): string => (string) $r['v'], $rows);
    }

    /** @return list<array{id:int,name:string}> */
    public function users(int $businessId): array
    {
        return $this->db->select('SELECT id, name FROM users WHERE business_id = ? ORDER BY name', [$businessId]);
    }
}
