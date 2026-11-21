<?php

declare(strict_types=1);

namespace Bic\Modules\Inventory;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use PDOException;

/**
 * Inventario: físico, reservado y disponible. El stock físico solo cambia con move(), que escribe un
 * movimiento inmutable y actualiza el saldo en la misma transacción. Valoración: costo promedio ponderado.
 */
final class InventoryService
{
    public const TYPES = [
        'purchase_receipt' => 'Recepción de compra', 'adjustment_in' => 'Ajuste de entrada', 'adjustment_out' => 'Ajuste de salida',
        'transfer_in' => 'Transferencia (entrada)', 'transfer_out' => 'Transferencia (salida)',
        'sale' => 'Venta', 'return' => 'Devolución',
    ];

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /**
     * Registra un movimiento y actualiza el saldo. DEBE llamarse dentro de una transacción.
     * Entradas: $unitCost obligatorio (costo por unidad, moneda base) y recalcula el promedio.
     * Salidas: salen al costo promedio vigente y no pueden superar el disponible (físico − reservado).
     *
     * @param array<string,mixed> $actor
     * @return int id del movimiento
     */
    public function move(array $actor, int $warehouseId, int $variantId, string $type, Decimal $signedQty, ?Decimal $unitCost, ?string $reason, ?string $refType, ?int $refId, string $nowUtc): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException('Tipo de movimiento no válido.');
        }
        $signedQty = $signedQty->rescale(3);
        if ($signedQty->isZero()) {
            throw new \InvalidArgumentException('La cantidad no puede ser cero.');
        }
        $biz = (int) $actor['business_id'];
        $level = $this->lockLevel($biz, $warehouseId, $variantId);
        $qty0 = Decimal::from($level['quantity'], 3);
        $avg0 = Decimal::from($level['avg_cost'], 4);

        if ($signedQty->isPositive()) {
            if ($unitCost === null || $unitCost->isNegative()) {
                throw new \InvalidArgumentException('Una entrada necesita costo unitario.');
            }
            $cost = $unitCost->rescale(4);
            $newQty = $qty0->add($signedQty);
            $newAvg = $qty0->isZero() ? $cost : $qty0->mul($avg0, 4)->add($signedQty->mul($cost, 4))->div($newQty, 4);
        } else {
            $out = $signedQty->abs();
            $available = $qty0->sub(Decimal::from($level['reserved_quantity'], 3));
            if ($out->compare($available) > 0) {
                throw new InsufficientStock('Stock insuficiente: disponible ' . $available->format() . ', se pidieron ' . $out->format() . '.');
            }
            $cost = $avg0;
            $newQty = $qty0->sub($out);
            $newAvg = $avg0;
        }

        $this->db->execute('UPDATE stock_levels SET quantity = ?, avg_cost = ? WHERE id = ?', [$newQty->toString(), $newAvg->toString(), $level['id']]);

        return $this->db->insert(
            'INSERT INTO stock_movements (business_id, warehouse_id, variant_id, movement_type, quantity, unit_cost, balance_after, avg_cost_after, reference_type, reference_id, reason, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$biz, $warehouseId, $variantId, $type, $signedQty->toString(), $cost->toString(), $newQty->toString(), $newAvg->toString(), $refType, $refId, $reason, $actor['id'], $nowUtc]
        );
    }

    /** Reserva stock para un pedido (sube reserved_quantity). Dentro de una transacción. No puede superar el disponible. */
    public function reserve(int $biz, int $warehouseId, int $variantId, Decimal $qty): void
    {
        $level = $this->lockLevel($biz, $warehouseId, $variantId);
        $available = Decimal::from($level['quantity'], 3)->sub(Decimal::from($level['reserved_quantity'], 3));
        if ($qty->compare($available) > 0) {
            throw new InsufficientStock('Stock insuficiente para reservar: disponible ' . $available->format() . ', se pidieron ' . $qty->format() . '.');
        }
        $this->db->execute('UPDATE stock_levels SET reserved_quantity = ? WHERE id = ?', [Decimal::from($level['reserved_quantity'], 3)->add($qty)->toString(), $level['id']]);
    }

    /** Libera una reserva (baja reserved_quantity). Dentro de una transacción. */
    public function release(int $biz, int $warehouseId, int $variantId, Decimal $qty): void
    {
        $level = $this->lockLevel($biz, $warehouseId, $variantId);
        $new = Decimal::from($level['reserved_quantity'], 3)->sub($qty);
        if ($new->isNegative()) {
            throw new \RuntimeException('La reserva a liberar supera lo reservado.');
        }
        $this->db->execute('UPDATE stock_levels SET reserved_quantity = ? WHERE id = ?', [$new->toString(), $level['id']]);
    }

    /** Fila de stock_levels bloqueada (se crea si no existe). Bloquear siempre en orden de almacén evita bloqueos mutuos. @return array<string,mixed> */
    public function lockLevel(int $biz, int $warehouseId, int $variantId): array
    {
        $sql = 'SELECT * FROM stock_levels WHERE warehouse_id = ? AND variant_id = ? AND business_id = ?' . $this->db->forUpdate();
        $row = $this->db->first($sql, [$warehouseId, $variantId, $biz]);
        if ($row === null) {
            try {
                $this->db->insert('INSERT INTO stock_levels (business_id, warehouse_id, variant_id) VALUES (?, ?, ?)', [$biz, $warehouseId, $variantId]);
            } catch (PDOException) {
                // otra petición la creó a la vez: se vuelve a leer
            }
            $row = $this->db->first($sql, [$warehouseId, $variantId, $biz]);
        }

        return $row ?? throw new \RuntimeException('No se pudo obtener el nivel de stock.');
    }

    /**
     * Ajuste manual (entrada/salida) o transferencia entre almacenes. El motivo es obligatorio.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in type(in|out|transfer), warehouse_id, to_warehouse_id, variant_id, quantity, unit_cost, reason
     * @return array{ok:bool,errors:array<string,string>,status:int}
     */
    public function adjust(array $actor, array $in, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $errors = [];
        $type = (string) ($in['type'] ?? '');
        if (!in_array($type, ['in', 'out', 'transfer'], true)) {
            return ['ok' => false, 'errors' => ['type' => 'Tipo no válido.'], 'status' => 422];
        }
        $wh = $this->activeWarehouse($biz, (int) ($in['warehouse_id'] ?? 0));
        if ($wh === null) {
            $errors['warehouse_id'] = 'Selecciona un almacén activo.';
        }
        $variant = $this->db->first('SELECT v.id FROM product_variants v WHERE v.id = ? AND v.business_id = ? AND v.is_active = 1', [(int) ($in['variant_id'] ?? 0), $biz]);
        if ($variant === null) {
            $errors['variant_id'] = 'Selecciona una variante activa.';
        }
        $qty = Decimal::fromInput($in['quantity'] ?? '', 3);
        if ($qty === null || !$qty->isPositive()) {
            $errors['quantity'] = 'La cantidad debe ser mayor que 0 (hasta 3 decimales).';
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            $errors['reason'] = 'Indica el motivo (máx. 255 caracteres).';
        }
        $to = null;
        if ($type === 'transfer') {
            $to = $this->activeWarehouse($biz, (int) ($in['to_warehouse_id'] ?? 0));
            if ($to === null) {
                $errors['to_warehouse_id'] = 'Selecciona el almacén de destino.';
            } elseif ($wh !== null && (int) $to['id'] === (int) $wh['id']) {
                $errors['to_warehouse_id'] = 'El origen y el destino deben ser distintos.';
            }
        }
        $cost = null;
        if ($type === 'in') {
            $raw = trim((string) ($in['unit_cost'] ?? ''));
            if ($raw !== '') {
                $cost = Decimal::fromInput($raw, 4);
                if ($cost === null || $cost->isNegative()) {
                    $errors['unit_cost'] = 'El costo debe ser un número mayor o igual a 0.';
                }
            } elseif ($variant !== null && $wh !== null) {
                $level = $this->db->first('SELECT quantity, avg_cost FROM stock_levels WHERE warehouse_id = ? AND variant_id = ?', [$wh['id'], $variant['id']]);
                if ($level !== null && Decimal::from($level['quantity'], 3)->isPositive()) {
                    $cost = Decimal::from($level['avg_cost'], 4); // sin costo explícito: al promedio actual
                } else {
                    $errors['unit_cost'] = 'Indica el costo unitario (aún no hay existencias para calcularlo).';
                }
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'status' => 422];
        }

        $variantId = (int) $variant['id'];
        try {
            $this->db->transaction(function () use ($actor, $biz, $type, $wh, $to, $variantId, $qty, $cost, $reason, $nowUtc): void {
                if ($type === 'transfer') {
                    foreach ([(int) $wh['id'], (int) $to['id']] as $w) { // bloqueo en orden fijo
                        $ids[] = $w;
                    }
                    sort($ids);
                    foreach ($ids as $w) {
                        $this->lockLevel($biz, $w, $variantId);
                    }
                    $out = $this->move($actor, (int) $wh['id'], $variantId, 'transfer_out', $qty->negate(), null, $reason, null, null, $nowUtc);
                    $unit = Decimal::from($this->db->value('SELECT unit_cost FROM stock_movements WHERE id = ?', [$out]), 4);
                    $this->move($actor, (int) $to['id'], $variantId, 'transfer_in', $qty, $unit, $reason, 'transfer', $out, $nowUtc);
                    $this->log($actor, 'inventory.transfer', $out, ['from' => $wh['id'], 'to' => $to['id'], 'variant_id' => $variantId, 'quantity' => $qty->toString()]);

                    return;
                }
                $id = $this->move($actor, (int) $wh['id'], $variantId, $type === 'in' ? 'adjustment_in' : 'adjustment_out', $type === 'in' ? $qty : $qty->negate(), $cost, $reason, null, null, $nowUtc);
                $this->log($actor, 'inventory.adjust_' . $type, $id, ['warehouse_id' => $wh['id'], 'variant_id' => $variantId, 'quantity' => $qty->toString(), 'reason' => $reason]);
            });
        } catch (InsufficientStock $e) {
            return ['ok' => false, 'errors' => ['quantity' => $e->getMessage()], 'status' => 422];
        }

        return ['ok' => true, 'errors' => [], 'status' => 200];
    }

    /** @return array<string,mixed>|null */
    public function activeWarehouse(int $biz, int $id): ?array
    {
        return $this->db->first('SELECT * FROM warehouses WHERE id = ? AND business_id = ? AND is_active = 1', [$id, $biz]);
    }

    /** @return list<array<string,mixed>> */
    public function warehouses(int $biz): array
    {
        return $this->db->select('SELECT id, code, name, is_default FROM warehouses WHERE business_id = ? AND is_active = 1 ORDER BY is_default DESC, name', [$biz]);
    }

    /** @return list<array<string,mixed>> */
    public function variantOptions(int $biz): array
    {
        return $this->db->select(
            "SELECT v.id, v.sku, v.name AS variant_name, p.name FROM product_variants v JOIN products p ON p.id = v.product_id
             WHERE v.business_id = ? AND v.is_active = 1 AND p.status <> 'discontinued' ORDER BY p.name, v.sku LIMIT 2000",
            [$biz]
        );
    }

    /**
     * Existencias por variante y almacén. 'low' filtra variantes cuyo disponible total ≤ stock mínimo.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function stock(int $biz, string $q, int $warehouseId, bool $lowOnly, int $limit, int $offset): array
    {
        $join = 'LEFT JOIN stock_levels sl ON sl.variant_id = v.id' . ($warehouseId > 0 ? ' AND sl.warehouse_id = ?' : '');
        $params = $warehouseId > 0 ? [$warehouseId] : [];
        $where = "v.business_id = ? AND v.is_active = 1 AND p.status = 'active'";
        $params[] = $biz;
        if ($q !== '') {
            $where .= " AND (p.name LIKE ? ESCAPE '!' OR v.sku LIKE ? ESCAPE '!' OR v.barcode LIKE ? ESCAPE '!')";
            $like = \Bic\Core\Paginator::like($q);
            array_push($params, $like, $like, $like);
        }
        $totalAvail = '(SELECT COALESCE(SUM(t.quantity - t.reserved_quantity), 0) FROM stock_levels t WHERE t.variant_id = v.id)';
        if ($lowOnly) {
            $where .= " AND p.min_stock > 0 AND {$totalAvail} <= p.min_stock";
        }
        $from = "FROM product_variants v JOIN products p ON p.id = v.product_id JOIN units u ON u.id = p.unit_id {$join} LEFT JOIN warehouses w ON w.id = sl.warehouse_id WHERE {$where}";
        $total = (int) $this->db->value("SELECT COUNT(*) {$from}", $params);
        $rows = $this->db->select(
            "SELECT v.id AS variant_id, v.sku, v.name AS variant_name, p.id AS product_id, p.name, p.min_stock, u.code AS unit,
                    sl.warehouse_id, w.name AS warehouse_name, COALESCE(sl.quantity, 0) AS quantity, COALESCE(sl.reserved_quantity, 0) AS reserved,
                    COALESCE(sl.avg_cost, 0) AS avg_cost, {$totalAvail} AS total_available
             {$from} ORDER BY p.name, v.sku, w.name LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        foreach ($rows as &$r) {
            $r['available'] = Decimal::from($r['quantity'], 3)->sub(Decimal::from($r['reserved'], 3))->toString();
            $r['value'] = Decimal::from($r['quantity'], 3)->mul(Decimal::from($r['avg_cost'], 4), 2)->toString();
            $min = Decimal::from($r['min_stock'], 3);
            $r['low'] = $min->isPositive() && Decimal::from($r['total_available'], 3)->compare($min) <= 0;
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total];
    }

    /** Valor total del inventario (suma de cantidad × costo promedio de cada nivel) y unidades físicas. @return array{value:string,levels:int} */
    public function valuation(int $biz): array
    {
        $value = Decimal::zero();
        $n = 0;
        foreach ($this->db->select('SELECT quantity, avg_cost FROM stock_levels WHERE business_id = ? AND quantity > 0', [$biz]) as $l) {
            $value = $value->add(Decimal::from($l['quantity'], 3)->mul(Decimal::from($l['avg_cost'], 4), 2));
            $n++;
        }

        return ['value' => $value->toString(), 'levels' => $n];
    }

    /**
     * @param array{variant_id?:int,warehouse_id?:int,type?:string,from?:string,to?:string} $f from/to en UTC (to exclusivo)
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function movements(int $biz, array $f, int $limit, int $offset): array
    {
        $where = ['m.business_id = ?'];
        $params = [$biz];
        foreach (['variant_id' => 'm.variant_id', 'warehouse_id' => 'm.warehouse_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $where[] = "{$col} = ?";
                $params[] = (int) $f[$k];
            }
        }
        if (!empty($f['type']) && isset(self::TYPES[$f['type']])) {
            $where[] = 'm.movement_type = ?';
            $params[] = $f['type'];
        }
        if (!empty($f['from'])) {
            $where[] = 'm.created_at >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'm.created_at < ?';
            $params[] = $f['to'];
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM stock_movements m WHERE {$w}", $params);
        $rows = $this->db->select(
            "SELECT m.*, v.sku, p.name AS product_name, wh.name AS warehouse_name, u.name AS user_name
             FROM stock_movements m JOIN product_variants v ON v.id = m.variant_id JOIN products p ON p.id = v.product_id
             JOIN warehouses wh ON wh.id = m.warehouse_id LEFT JOIN users u ON u.id = m.created_by
             WHERE {$w} ORDER BY m.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Conciliación: el saldo de cada nivel debe ser la suma de sus movimientos y el último saldo_posterior;
     * lo reservado nunca supera lo físico. Lista vacía = todo cuadra.
     *
     * @return list<string>
     */
    public function reconcile(int $biz): array
    {
        $issues = [];
        $sums = [];
        foreach ($this->db->select('SELECT warehouse_id, variant_id, SUM(quantity) AS total FROM stock_movements WHERE business_id = ? GROUP BY warehouse_id, variant_id', [$biz]) as $r) {
            $sums[$r['warehouse_id'] . ':' . $r['variant_id']] = Decimal::from($r['total'], 3);
        }
        foreach ($this->db->select('SELECT * FROM stock_levels WHERE business_id = ?', [$biz]) as $l) {
            $key = $l['warehouse_id'] . ':' . $l['variant_id'];
            $qty = Decimal::from($l['quantity'], 3);
            if (!$qty->equals($sums[$key] ?? Decimal::zero(3))) {
                $issues[] = "Variante {$l['variant_id']} / almacén {$l['warehouse_id']}: el saldo ({$qty->toString()}) no coincide con la suma de movimientos.";
            }
            if (Decimal::from($l['reserved_quantity'], 3)->compare($qty) > 0) {
                $issues[] = "Variante {$l['variant_id']} / almacén {$l['warehouse_id']}: lo reservado supera el stock físico.";
            }
            $last = $this->db->value('SELECT balance_after FROM stock_movements WHERE warehouse_id = ? AND variant_id = ? ORDER BY id DESC LIMIT 1', [$l['warehouse_id'], $l['variant_id']]);
            if ($last !== null && !Decimal::from($last, 3)->equals($qty)) {
                $issues[] = "Variante {$l['variant_id']} / almacén {$l['warehouse_id']}: el último saldo del kardex no coincide con el stock.";
            }
        }

        $res = [];
        foreach ($this->db->select("SELECT warehouse_id, variant_id, SUM(quantity) AS total FROM stock_reservations WHERE business_id = ? AND status = 'active' GROUP BY warehouse_id, variant_id", [$biz]) as $r) {
            $res[$r['warehouse_id'] . ':' . $r['variant_id']] = Decimal::from($r['total'], 3);
        }
        foreach ($this->db->select('SELECT warehouse_id, variant_id, reserved_quantity FROM stock_levels WHERE business_id = ?', [$biz]) as $l) {
            $key = $l['warehouse_id'] . ':' . $l['variant_id'];
            if (!Decimal::from($l['reserved_quantity'], 3)->equals($res[$key] ?? Decimal::zero(3))) {
                $issues[] = "Variante {$l['variant_id']} / almacén {$l['warehouse_id']}: lo reservado no coincide con las reservas activas.";
            }
        }

        return $issues;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $new */
    private function log(array $actor, string $action, int $id, array $new): void
    {
        $this->audit->log((int) $actor['business_id'], (int) $actor['id'], $action, 'stock_movements', $id, null, $new, $actor['ip'] ?? null, $actor['ua'] ?? null);
    }
}
