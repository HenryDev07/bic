<?php

declare(strict_types=1);

namespace Bic\Modules\Customers;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Paginator;

final class CustomerService
{
    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $biz, int $id): ?array
    {
        return $this->db->first('SELECT * FROM customers WHERE id = ? AND business_id = ?', [$id, $biz]);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $biz, string $q, int $limit, int $offset): array
    {
        $where = 'business_id = ?';
        $params = [$biz];
        if ($q !== '') {
            $like = Paginator::like($q);
            $where .= " AND (name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' OR tax_id LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like);
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM customers WHERE {$where}", $params);
        $rows = $this->db->select("SELECT * FROM customers WHERE {$where} ORDER BY name LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function options(int $biz): array
    {
        return $this->db->select('SELECT id, name FROM customers WHERE business_id = ? AND is_active = 1 ORDER BY name LIMIT 2000', [$biz]);
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function save(array $actor, ?int $id, array $in): array
    {
        $biz = (int) $actor['business_id'];
        $old = $id !== null ? $this->find($biz, $id) : null;
        if ($id !== null && $old === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        $t = static fn (string $k): string => trim((string) ($in[$k] ?? ''));
        $errors = [];
        $name = $t('name');
        if ($name === '' || mb_strlen($name) > 190) {
            $errors['name'] = 'El nombre es obligatorio (máx. 190 caracteres).';
        }
        $email = $t('email');
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            $errors['email'] = 'Correo no válido.';
        }
        foreach (['tax_id' => 50, 'phone' => 50, 'address' => 255, 'notes' => 2000] as $k => $max) {
            if (mb_strlen($t($k)) > $max) {
                $errors[$k] = "Máximo {$max} caracteres.";
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $id, 'status' => 422];
        }
        $n = static fn (string $v): ?string => $v === '' ? null : $v;
        $v = ['name' => $name, 'tax_id' => $n($t('tax_id')), 'email' => $n($email), 'phone' => $n($t('phone')), 'address' => $n($t('address')),
            'notes' => $n($t('notes')), 'is_active' => $id === null || !empty($in['is_active']) ? 1 : 0];

        $newId = $this->db->transaction(function () use ($biz, $id, $v, $old, $actor): int {
            if ($id === null) {
                $cols = array_keys($v);
                $newId = $this->db->insert('INSERT INTO customers (business_id, ' . implode(', ', $cols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_merge([$biz], array_values($v)));
            } else {
                $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($v)));
                $this->db->execute("UPDATE customers SET {$sets} WHERE id = ? AND business_id = ?", array_merge(array_values($v), [$id, $biz]));
                $newId = $id;
            }
            $this->audit->log($biz, (int) $actor['id'], $id === null ? 'customer.create' : 'customer.update', 'customers', $newId,
                $old === null ? null : array_intersect_key($old, $v), $v, $actor['ip'] ?? null, $actor['ua'] ?? null);

            return $newId;
        });

        return ['ok' => true, 'errors' => [], 'id' => $newId, 'status' => 200];
    }
}
