<?php

declare(strict_types=1);

namespace Bic\Modules\Catalog;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;

/** Categorías, marcas, unidades e impuestos: tablas auxiliares con las mismas reglas básicas. */
final class CatalogService
{
    public const ENTITIES = [
        'categories' => ['table' => 'categories', 'title' => 'Categorías', 'singular' => 'categoría', 'order' => 'name', 'cols' => ['name' => 'Nombre']],
        'brands'     => ['table' => 'brands', 'title' => 'Marcas', 'singular' => 'marca', 'order' => 'name', 'cols' => ['name' => 'Nombre']],
        'units'      => ['table' => 'units', 'title' => 'Unidades', 'singular' => 'unidad', 'order' => 'code', 'cols' => ['code' => 'Código', 'name' => 'Nombre']],
        'tax-rates'  => ['table' => 'tax_rates', 'title' => 'Impuestos', 'singular' => 'impuesto', 'order' => 'name', 'cols' => ['name' => 'Nombre', 'rate' => 'Tasa %']],
    ];

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    public static function exists(string $entity): bool
    {
        return isset(self::ENTITIES[$entity]);
    }

    /** @return list<array<string,mixed>> */
    public function all(int $businessId, string $entity): array
    {
        $cfg = self::ENTITIES[$entity];

        return $this->db->select("SELECT * FROM {$cfg['table']} WHERE business_id = ? ORDER BY {$cfg['order']}, id", [$businessId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $businessId, string $entity, int $id): ?array
    {
        return $this->db->first('SELECT * FROM ' . self::ENTITIES[$entity]['table'] . ' WHERE id = ? AND business_id = ?', [$id, $businessId]);
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $input
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function save(array $actor, string $entity, ?int $id, array $input): array
    {
        $cfg = self::ENTITIES[$entity];
        $biz = (int) $actor['business_id'];
        $old = $id !== null ? $this->find($biz, $entity, $id) : null;
        if ($id !== null && $old === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }

        $errors = [];
        $values = ['is_active' => !empty($input['is_active']) ? 1 : 0];
        $name = trim((string) ($input['name'] ?? ''));
        $max = $entity === 'units' ? 60 : ($entity === 'tax-rates' ? 80 : 120);
        if ($name === '') {
            $errors['name'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($name) > $max) {
            $errors['name'] = "El nombre no puede superar {$max} caracteres.";
        }
        $values['name'] = $name;

        if ($entity === 'units') {
            $code = mb_strtolower(trim((string) ($input['code'] ?? '')));
            if (!preg_match('/^[a-z0-9._-]{1,10}$/', $code)) {
                $errors['code'] = 'El código usa 1–10 letras, números, punto o guion.';
            }
            $values['code'] = $code;
        }

        $makeDefault = false;
        if ($entity === 'tax-rates') {
            $rate = Decimal::fromInput($input['rate'] ?? '', 4);
            if ($rate === null || $rate->isNegative() || $rate->compare(Decimal::from(100, 4)) > 0) {
                $errors['rate'] = 'La tasa debe estar entre 0 y 100 (hasta 4 decimales). Para exento usa 0.';
            } else {
                $values['rate'] = $rate->toString();
            }
            $makeDefault = !empty($input['is_default']);
            $wasDefault = $old !== null && (int) $old['is_default'] === 1;
            if ($wasDefault && !$makeDefault) {
                $errors['is_default'] = 'Debe existir un impuesto predeterminado: márcalo en otro para cambiarlo.';
                $makeDefault = true;
            }
            if ($makeDefault && $values['is_active'] === 0) {
                $errors['is_active'] = 'El impuesto predeterminado debe estar activo.';
            }
        }

        $unique = $entity === 'units' ? 'code' : 'name';
        if (!isset($errors[$unique]) && $this->taken($biz, $cfg['table'], $unique, (string) $values[$unique], $id)) {
            $errors[$unique] = 'Ya existe un registro con ese valor.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }

        $newId = $this->db->transaction(function () use ($cfg, $biz, $id, $values, $entity, $makeDefault, $actor, $old): int {
            if ($id === null) {
                $cols = array_keys($values);
                $sql = 'INSERT INTO ' . $cfg['table'] . ' (business_id, ' . implode(', ', $cols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')';
                $newId = $this->db->insert($sql, array_merge([$biz], array_values($values)));
            } else {
                $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($values)));
                $this->db->execute("UPDATE {$cfg['table']} SET {$sets} WHERE id = ? AND business_id = ?", array_merge(array_values($values), [$id, $biz]));
                $newId = $id;
            }
            if ($entity === 'tax-rates') {
                $this->applyDefault($biz, $newId, $makeDefault);
            }
            $this->audit->log($biz, (int) $actor['id'], 'catalog.' . $entity . ($id === null ? '.create' : '.update'), $cfg['table'], $newId,
                $old === null ? null : array_intersect_key($old, $values), $values, $actor['ip'] ?? null, $actor['ua'] ?? null);

            return $newId;
        });

        return ['ok' => true, 'errors' => [], 'id' => $newId, 'status' => 200];
    }

    private function applyDefault(int $biz, int $id, bool $makeDefault): void
    {
        $hasDefault = (int) $this->db->value('SELECT COUNT(*) FROM tax_rates WHERE business_id = ? AND is_default = 1 AND id <> ?', [$biz, $id]);
        if ($makeDefault) {
            $this->db->execute('UPDATE tax_rates SET is_default = 0 WHERE business_id = ? AND id <> ?', [$biz, $id]);
            $this->db->execute('UPDATE tax_rates SET is_default = 1 WHERE id = ? AND business_id = ?', [$id, $biz]);
        } elseif ($hasDefault === 0) {
            // Primer impuesto del negocio: queda como predeterminado.
            $this->db->execute('UPDATE tax_rates SET is_default = 1 WHERE id = ? AND business_id = ?', [$id, $biz]);
        }
    }

    private function taken(int $biz, string $table, string $field, string $value, ?int $exceptId): bool
    {
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM {$table} WHERE business_id = ? AND LOWER({$field}) = LOWER(?) AND id <> ?",
            [$biz, $value, $exceptId ?? 0]
        ) > 0;
    }
}
