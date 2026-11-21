<?php

declare(strict_types=1);

namespace Bic\Modules\Sales;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use Bic\Core\Money\TaxCalculator;
use Bic\Core\Paginator;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Finance\InsufficientFunds;
use Bic\Modules\Inventory\InsufficientStock;
use Bic\Modules\Inventory\InventoryService;
use InvalidArgumentException;
use OverflowException;
use PDOException;

/**
 * Pedidos → ventas. El stock físico sale y el ingreso se reconoce solo al entregar. Antes de entregar,
 * el dinero recibido son anticipos (pasivo). Todo en moneda base. Los totales se calculan, no se guardan.
 */
final class SalesService
{
    public const STATUSES = ['draft' => 'Borrador', 'pending' => 'Pendiente', 'confirmed' => 'Confirmado', 'prepared' => 'Preparado', 'delivered' => 'Entregado', 'cancelled' => 'Cancelado'];
    public const RESERVE_OPTIONS = ['pending' => 'Al pasar a pendiente', 'confirmed' => 'Al confirmar', 'never' => 'No reservar'];
    private const NEXT = ['draft' => ['pending'], 'pending' => ['confirmed', 'delivered'], 'confirmed' => ['prepared', 'delivered'], 'prepared' => ['delivered']];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly InventoryService $inventory,
        private readonly FinanceService $finance,
    ) {
    }

    // ---------- configuración ----------

    public function reserveOn(int $biz): string
    {
        $v = (string) $this->db->value("SELECT setting_value FROM settings WHERE business_id = ? AND setting_key = 'order_reserve_stock_on'", [$biz]);

        return isset(self::RESERVE_OPTIONS[$v]) ? $v : 'confirmed';
    }

    /** @param array<string,mixed> $actor */
    public function setReserveOn(array $actor, string $value): bool
    {
        if (!isset(self::RESERVE_OPTIONS[$value])) {
            return false;
        }
        $biz = (int) $actor['business_id'];
        $old = $this->reserveOn($biz);
        if ($this->db->value("SELECT id FROM settings WHERE business_id = ? AND setting_key = 'order_reserve_stock_on'", [$biz]) === null) {
            $this->db->execute("INSERT INTO settings (business_id, setting_key, setting_value, value_type, group_name) VALUES (?, 'order_reserve_stock_on', ?, 'string', 'sales')", [$biz, $value]);
        } else {
            $this->db->execute("UPDATE settings SET setting_value = ? WHERE business_id = ? AND setting_key = 'order_reserve_stock_on'", [$value, $biz]);
        }
        $this->audit->log($biz, (int) $actor['id'], 'settings.update', 'settings', null, ['order_reserve_stock_on' => $old], ['order_reserve_stock_on' => $value], $actor['ip'] ?? null, $actor['ua'] ?? null);

        return true;
    }

    /** ¿Un pedido en este estado debe tener su stock reservado? */
    private function holdsReservation(string $reserveOn, string $status): bool
    {
        return match ($reserveOn) {
            'pending' => in_array($status, ['pending', 'confirmed', 'prepared'], true),
            'confirmed' => in_array($status, ['confirmed', 'prepared'], true),
            default => false,
        };
    }

    // ---------- lectura ----------

    /** @return array<string,mixed>|null */
    public function find(int $biz, int $id): ?array
    {
        return $this->db->first(
            'SELECT o.*, c.name AS customer_name, w.name AS warehouse_name FROM orders o LEFT JOIN customers c ON c.id = o.customer_id JOIN warehouses w ON w.id = o.warehouse_id WHERE o.id = ? AND o.business_id = ?',
            [$id, $biz]
        );
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $biz, int $id): array
    {
        return $this->db->select(
            'SELECT l.*, v.sku, p.name AS product_name FROM order_lines l JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id WHERE l.order_id = ? AND l.business_id = ? ORDER BY l.id',
            [$id, $biz]
        );
    }

    /** @return list<array<string,mixed>> */
    public function payments(int $biz, int $id): array
    {
        return $this->db->select(
            'SELECT op.id, op.kind, op.notes, op.financial_transaction_id, t.amount, t.occurred_at, t.voided_at, t.void_reason, a.name AS account_name
             FROM order_payments op JOIN financial_transactions t ON t.id = op.financial_transaction_id JOIN financial_accounts a ON a.id = t.account_id
             WHERE op.order_id = ? AND op.business_id = ? ORDER BY op.id',
            [$id, $biz]
        );
    }

    /** @return list<array<string,mixed>> */
    public function history(int $biz, int $id): array
    {
        return $this->db->select(
            'SELECT h.*, u.name AS user_name FROM order_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.order_id = ? AND h.business_id = ? ORDER BY h.id',
            [$id, $biz]
        );
    }

    /** @return list<array<string,mixed>> */
    public function returns(int $biz, int $id): array
    {
        $rows = $this->db->select('SELECT r.*, u.name AS user_name FROM order_returns r LEFT JOIN users u ON u.id = r.created_by WHERE r.order_id = ? AND r.business_id = ? ORDER BY r.id', [$id, $biz]);
        foreach ($rows as &$r) {
            $r['lines'] = $this->db->select(
                'SELECT rl.*, p.name AS product_name, v.sku FROM order_return_lines rl JOIN order_lines l ON l.id = rl.order_line_id JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id WHERE rl.return_id = ?',
                [$r['id']]
            );
            $total = Decimal::zero();
            foreach ($r['lines'] as $l) {
                $total = $total->add(Decimal::from($l['line_total'], 2));
            }
            $r['total'] = $total->toString();
        }
        unset($r);

        return $rows;
    }

    /**
     * Totales calculados. balance > 0: el cliente debe; balance < 0: hay saldo a favor del cliente (reembolsable).
     *
     * @param array<string,mixed> $order
     * @return array<string,Decimal>
     */
    public function totals(array $order, int $biz): array
    {
        $id = (int) $order['id'];
        $net = $tax = $total = $cost = $retNet = $retTax = $retTotal = Decimal::zero();
        foreach ($this->lines($biz, $id) as $l) {
            $net = $net->add(Decimal::from($l['net_amount'], 2));
            $tax = $tax->add(Decimal::from($l['tax_amount'], 2));
            $total = $total->add(Decimal::from($l['line_total'], 2));
            if ($l['unit_cost_snapshot'] !== null) {
                $kept = Decimal::from($l['quantity'], 3)->sub(Decimal::from($l['returned_quantity'], 3));
                $cost = $cost->add($kept->mul(Decimal::from($l['unit_cost_snapshot'], 4), 2));
            }
        }
        $row = $this->db->first(
            'SELECT COALESCE(SUM(rl.net_amount), 0) AS n, COALESCE(SUM(rl.tax_amount), 0) AS t, COALESCE(SUM(rl.line_total), 0) AS s
             FROM order_return_lines rl JOIN order_returns r ON r.id = rl.return_id WHERE r.order_id = ? AND r.business_id = ?',
            [$id, $biz]
        );
        $retNet = Decimal::from($row['n'] ?? 0, 2);
        $retTax = Decimal::from($row['t'] ?? 0, 2);
        $retTotal = Decimal::from($row['s'] ?? 0, 2);
        $paid = $refunded = Decimal::zero();
        foreach ($this->payments($biz, $id) as $p) {
            if ($p['voided_at'] !== null) {
                continue;
            }
            $amount = Decimal::from($p['amount'], 2);
            $p['kind'] === 'refund' ? $refunded = $refunded->add($amount) : $paid = $paid->add($amount);
        }
        $netTotal = $total->sub($retTotal);
        $netPaid = $paid->sub($refunded);

        return [
            'net' => $net->sub($retNet), 'tax' => $tax->sub($retTax), 'gross' => $total, 'returned' => $retTotal, 'total' => $netTotal,
            'paid' => $paid, 'refunded' => $refunded, 'net_paid' => $netPaid, 'balance' => $netTotal->sub($netPaid), 'cost' => $cost,
            'profit' => $net->sub($retNet)->sub($cost),
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $biz, string $q, string $status, int $limit, int $offset): array
    {
        $where = ['o.business_id = ?'];
        $params = [$biz];
        if ($q !== '') {
            $like = Paginator::like($q);
            $where[] = "(o.order_number LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!')";
            array_push($params, $like, $like);
        }
        if (isset(self::STATUSES[$status])) {
            $where[] = 'o.status = ?';
            $params[] = $status;
        }
        $w = implode(' AND ', $where);
        $from = 'FROM orders o LEFT JOIN customers c ON c.id = o.customer_id';
        $total = (int) $this->db->value("SELECT COUNT(*) {$from} WHERE {$w}", $params);
        $rows = $this->db->select(
            "SELECT o.*, c.name AS customer_name, (SELECT COALESCE(SUM(l.line_total), 0) FROM order_lines l WHERE l.order_id = o.id) AS total {$from} WHERE {$w} ORDER BY o.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function variantOptions(int $biz): array
    {
        return $this->inventory->variantOptions($biz);
    }

    // ---------- borrador ----------

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in customer_id (opcional), warehouse_id, notes
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function save(array $actor, ?int $id, array $in): array
    {
        $biz = (int) $actor['business_id'];
        $old = $id !== null ? $this->find($biz, $id) : null;
        if ($id !== null && $old === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        if ($old !== null && $old['status'] !== 'draft') {
            return ['ok' => false, 'errors' => ['status' => 'Solo se edita el encabezado de un borrador.'], 'id' => $id, 'status' => 422];
        }
        $errors = [];
        $customerId = (int) ($in['customer_id'] ?? 0);
        if ($customerId > 0 && $this->db->first('SELECT id FROM customers WHERE id = ? AND business_id = ? AND is_active = 1', [$customerId, $biz]) === null) {
            $errors['customer_id'] = 'Cliente no válido.';
        }
        $wh = $this->inventory->activeWarehouse($biz, (int) ($in['warehouse_id'] ?? 0));
        if ($wh === null) {
            $errors['warehouse_id'] = 'Selecciona un almacén activo.';
        }
        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > 500) {
            $errors['notes'] = 'Máximo 500 caracteres.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $id, 'status' => 422];
        }
        $cust = $customerId > 0 ? $customerId : null;
        $n = $notes === '' ? null : $notes;

        $newId = $this->db->transaction(function () use ($biz, $id, $cust, $wh, $n, $actor, $old): int {
            if ($id === null) {
                $newId = $this->db->insert(
                    "INSERT INTO orders (business_id, order_number, customer_id, warehouse_id, status, notes, created_by) VALUES (?, ?, ?, ?, 'draft', ?, ?)",
                    [$biz, 'TMP-' . bin2hex(random_bytes(6)), $cust, $wh['id'], $n, $actor['id']]
                );
                $this->db->execute('UPDATE orders SET order_number = ? WHERE id = ?', [sprintf('PE-%05d', $newId), $newId]);
                $this->db->execute("INSERT INTO order_status_history (business_id, order_id, from_status, to_status, changed_by) VALUES (?, ?, NULL, 'draft', ?)", [$biz, $newId, $actor['id']]);
            } else {
                $this->db->execute('UPDATE orders SET customer_id = ?, warehouse_id = ?, notes = ? WHERE id = ? AND business_id = ?', [$cust, $wh['id'], $n, $id, $biz]);
                $newId = $id;
            }
            $this->log($actor, $id === null ? 'order.create' : 'order.update', $newId, $old === null ? null : ['customer_id' => $old['customer_id'], 'warehouse_id' => $old['warehouse_id']], ['customer_id' => $cust, 'warehouse_id' => $wh['id']]);

            return $newId;
        });

        return ['ok' => true, 'errors' => [], 'id' => $newId, 'status' => 200];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in variant_id, quantity, unit_price (vacío = promo o precio de lista), tax_rate (vacío = el del producto)
     * @return array{ok:bool,errors:array<string,string>,status:int}
     */
    public function addLine(array $actor, int $id, array $in): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'errors' => [], 'status' => 404];
        }
        if ($order['status'] !== 'draft') {
            return ['ok' => false, 'errors' => ['status' => 'Solo se cambian líneas en un borrador.'], 'status' => 422];
        }
        $errors = [];
        $variant = $this->db->first(
            "SELECT v.id, p.sale_price, p.promo_price, p.price_includes_tax, t.rate AS tax_rate FROM product_variants v JOIN products p ON p.id = v.product_id JOIN tax_rates t ON t.id = p.tax_rate_id
             WHERE v.id = ? AND v.business_id = ? AND v.is_active = 1 AND p.status = 'active'",
            [(int) ($in['variant_id'] ?? 0), $biz]
        );
        if ($variant === null) {
            $errors['variant_id'] = 'Selecciona una variante activa.';
        } elseif ((int) $this->db->value('SELECT COUNT(*) FROM order_lines WHERE order_id = ? AND variant_id = ?', [$id, $variant['id']]) > 0) {
            $errors['variant_id'] = 'Esa variante ya está en el pedido: quita la línea para cambiarla.';
        }
        $qty = Decimal::fromInput($in['quantity'] ?? '', 3);
        if ($qty === null || !$qty->isPositive()) {
            $errors['quantity'] = 'La cantidad debe ser mayor que 0 (hasta 3 decimales).';
        }
        $rawPrice = trim((string) ($in['unit_price'] ?? ''));
        if ($rawPrice === '' && $variant !== null) {
            $rawPrice = (string) ($variant['promo_price'] ?? $variant['sale_price']);
        }
        $price = $rawPrice === '' ? null : Decimal::fromInput($rawPrice, 2);
        if ($price === null || $price->isNegative()) {
            $errors['unit_price'] = 'El precio debe ser un número mayor o igual a 0 (2 decimales).';
        }
        $rawTax = trim((string) ($in['tax_rate'] ?? ''));
        $rate = $rawTax === '' ? ($variant !== null ? Decimal::from($variant['tax_rate'], 4) : null) : Decimal::fromInput($rawTax, 4);
        if ($rate === null || $rate->isNegative() || $rate->compare(Decimal::from(100, 4)) > 0) {
            $errors['tax_rate'] = 'La tasa debe estar entre 0 y 100.';
        }
        $includes = $variant !== null ? (int) $variant['price_includes_tax'] : 0;
        $calc = null;
        if ($errors === []) {
            try {
                $calc = TaxCalculator::line($price, $qty, $rate, $includes === 1);
            } catch (OverflowException | InvalidArgumentException) {
                $errors['quantity'] = 'Los importes son demasiado grandes.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'status' => 422];
        }
        $this->db->transaction(function () use ($biz, $id, $variant, $qty, $price, $rate, $includes, $calc, $actor): void {
            $lineId = $this->db->insert(
                'INSERT INTO order_lines (business_id, order_id, variant_id, quantity, unit_price, price_includes_tax, tax_rate, net_amount, tax_amount, line_total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$biz, $id, $variant['id'], $qty->toString(), $price->toString(), $includes, $rate->toString(), $calc['net']->toString(), $calc['tax']->toString(), $calc['gross']->toString()]
            );
            $this->log($actor, 'order.line_add', $id, null, ['line_id' => $lineId, 'variant_id' => $variant['id'], 'quantity' => $qty->toString(), 'unit_price' => $price->toString()]);
        });

        return ['ok' => true, 'errors' => [], 'status' => 200];
    }

    /** @param array<string,mixed> $actor @return array{ok:bool,error:?string,status:int} */
    public function removeLine(array $actor, int $id, int $lineId): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        if ($order['status'] !== 'draft') {
            return ['ok' => false, 'error' => 'Solo se quitan líneas de un borrador.', 'status' => 422];
        }
        $n = $this->db->execute('DELETE FROM order_lines WHERE id = ? AND order_id = ? AND business_id = ?', [$lineId, $id, $biz]);
        if ($n === 0) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        $this->log($actor, 'order.line_remove', $id, ['line_id' => $lineId], null);

        return ['ok' => true, 'error' => null, 'status' => 200];
    }

    // ---------- ciclo de vida ----------

    /**
     * Avanza el estado. Reserva stock según la configuración; al entregar consume reservas, saca stock al costo
     * promedio y congela unit_cost_snapshot.
     *
     * @param array<string,mixed> $actor
     * @return array{ok:bool,error:?string,status:int}
     */
    public function advance(array $actor, int $id, string $to, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        try {
            return $this->db->transaction(function () use ($actor, $biz, $id, $to, $nowUtc): array {
                $order = $this->lockOrder($biz, $id);
                if ($order === null) {
                    return ['ok' => false, 'error' => null, 'status' => 404];
                }
                $from = (string) $order['status'];
                if (!in_array($to, self::NEXT[$from] ?? [], true)) {
                    return ['ok' => false, 'error' => 'Transición no permitida: ' . (self::STATUSES[$from] ?? $from) . ' → ' . (self::STATUSES[$to] ?? $to) . '.', 'status' => 422];
                }
                $lines = $this->lines($biz, $id);
                if ($lines === []) {
                    return ['ok' => false, 'error' => 'El pedido no tiene líneas.', 'status' => 422];
                }
                usort($lines, static fn (array $a, array $b): int => (int) $a['variant_id'] <=> (int) $b['variant_id']); // bloqueo en orden fijo
                $reserveOn = $this->reserveOn($biz);
                $wh = (int) $order['warehouse_id'];

                if ($to !== 'delivered' && !$this->holdsReservation($reserveOn, $from) && $this->holdsReservation($reserveOn, $to)) {
                    foreach ($lines as $l) {
                        $this->inventory->reserve($biz, $wh, (int) $l['variant_id'], Decimal::from($l['quantity'], 3));
                        $this->db->insert(
                            "INSERT INTO stock_reservations (business_id, order_line_id, warehouse_id, variant_id, quantity, status) VALUES (?, ?, ?, ?, ?, 'active')",
                            [$biz, $l['id'], $wh, $l['variant_id'], Decimal::from($l['quantity'], 3)->toString()]
                        );
                    }
                }
                $sets = ['status = ?'];
                $params = [$to];
                if ($to === 'confirmed') {
                    $sets[] = 'confirmed_at = ?';
                    $params[] = $nowUtc;
                }
                if ($to === 'delivered') {
                    $this->deliver($actor, $biz, $order, $lines, $nowUtc);
                    $sets[] = 'delivered_at = ?';
                    $params[] = $nowUtc;
                }
                $this->db->execute('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND business_id = ?', array_merge($params, [$id, $biz]));
                $this->recordStatus($biz, $id, $from, $to, null, (int) $actor['id'], $nowUtc);
                $this->log($actor, 'order.' . $to, $id, ['status' => $from], ['status' => $to]);

                return ['ok' => true, 'error' => null, 'status' => 200];
            });
        } catch (InsufficientStock $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'status' => 422];
        }
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $order @param list<array<string,mixed>> $lines */
    private function deliver(array $actor, int $biz, array $order, array $lines, string $nowUtc): void
    {
        $wh = (int) $order['warehouse_id'];
        foreach ($lines as $l) {
            $qty = Decimal::from($l['quantity'], 3);
            $res = $this->db->first("SELECT id, quantity FROM stock_reservations WHERE order_line_id = ? AND status = 'active'", [$l['id']]);
            if ($res !== null) { // la reserva se consume: sale de lo reservado y luego del físico
                $this->inventory->release($biz, $wh, (int) $l['variant_id'], Decimal::from($res['quantity'], 3));
                $this->db->execute("UPDATE stock_reservations SET status = 'consumed' WHERE id = ?", [$res['id']]);
            }
            $moveId = $this->inventory->move($actor, $wh, (int) $l['variant_id'], 'sale', $qty->negate(), null, $order['order_number'], 'order', (int) $order['id'], $nowUtc);
            $cost = $this->db->value('SELECT unit_cost FROM stock_movements WHERE id = ?', [$moveId]);
            $this->db->execute('UPDATE order_lines SET unit_cost_snapshot = ? WHERE id = ?', [Decimal::from($cost, 4)->toString(), $l['id']]);
        }
    }

    /**
     * Cancela un pedido no entregado: libera reservas. Exige que los anticipos ya estén reembolsados.
     *
     * @param array<string,mixed> $actor
     * @return array{ok:bool,error:?string,status:int}
     */
    public function cancel(array $actor, int $id, string $reason, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            return ['ok' => false, 'error' => 'Indica el motivo de la cancelación (máx. 255 caracteres).', 'status' => 422];
        }

        return $this->db->transaction(function () use ($actor, $biz, $id, $reason, $nowUtc): array {
            $order = $this->lockOrder($biz, $id);
            if ($order === null) {
                return ['ok' => false, 'error' => null, 'status' => 404];
            }
            $from = (string) $order['status'];
            if (in_array($from, ['delivered', 'cancelled'], true)) {
                return ['ok' => false, 'error' => $from === 'delivered' ? 'Un pedido entregado no se cancela: registra una devolución.' : 'El pedido ya está cancelado.', 'status' => 422];
            }
            $t = $this->totals($order, $biz);
            if ($t['net_paid']->isPositive()) {
                return ['ok' => false, 'error' => 'Hay ' . $t['net_paid']->format() . ' recibidos en anticipos: reembólsalos antes de cancelar.', 'status' => 422];
            }
            $active = $this->db->select("SELECT id, variant_id, quantity FROM stock_reservations WHERE business_id = ? AND status = 'active' AND order_line_id IN (SELECT id FROM order_lines WHERE order_id = ?) ORDER BY variant_id", [$biz, $id]);
            foreach ($active as $r) {
                $this->inventory->release($biz, (int) $order['warehouse_id'], (int) $r['variant_id'], Decimal::from($r['quantity'], 3));
                $this->db->execute("UPDATE stock_reservations SET status = 'released' WHERE id = ?", [$r['id']]);
            }
            $this->db->execute("UPDATE orders SET status = 'cancelled', cancelled_at = ?, cancel_reason = ? WHERE id = ? AND business_id = ?", [$nowUtc, $reason, $id, $biz]);
            $this->recordStatus($biz, $id, $from, 'cancelled', $reason, (int) $actor['id'], $nowUtc);
            $this->log($actor, 'order.cancel', $id, ['status' => $from], ['status' => 'cancelled', 'reason' => $reason]);

            return ['ok' => true, 'error' => null, 'status' => 200];
        });
    }

    // ---------- cobros ----------

    /**
     * Cobro (pedido entregado) o anticipo (aún no entregado), en moneda base. Idempotente con $in['token'].
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in account_id, amount, notes, token
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int,duplicate?:bool}
     */
    public function pay(array $actor, int $id, array $in, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        if (in_array($order['status'], ['draft', 'cancelled'], true)) {
            return ['ok' => false, 'errors' => ['amount' => 'Solo se cobran pedidos activos (no borradores ni cancelados).'], 'id' => null, 'status' => 422];
        }
        $token = trim((string) ($in['token'] ?? ''));
        $tokenHash = $token === '' ? null : hash('sha256', $token);
        if ($tokenHash !== null) {
            $prev = $this->db->first("SELECT result_id FROM idempotency_keys WHERE business_id = ? AND scope = 'order_pay' AND token = ?", [$biz, $tokenHash]);
            if ($prev !== null) {
                return ['ok' => true, 'errors' => [], 'id' => $prev['result_id'] === null ? null : (int) $prev['result_id'], 'status' => 200, 'duplicate' => true];
            }
        }
        $errors = [];
        $account = $this->finance->account($biz, (int) ($in['account_id'] ?? 0));
        if ($account === null || (int) $account['is_active'] !== 1) {
            $errors['account_id'] = 'Selecciona una cuenta activa.';
        } elseif ($account['currency'] !== 'USD') {
            $errors['account_id'] = 'Las ventas se cobran en una cuenta en moneda base (USD).';
        }
        $amount = Decimal::fromInput($in['amount'] ?? '', 2);
        if ($amount === null || !$amount->isPositive()) {
            $errors['amount'] = 'El importe debe ser mayor que 0.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }
        $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 255);

        try {
            $payId = $this->db->transaction(function () use ($actor, $biz, $id, $account, $amount, $notes, $nowUtc, $tokenHash): int {
                $order = $this->lockOrder($biz, $id) ?? throw new \RuntimeException('Pedido no encontrado.');
                $this->db->value('SELECT id FROM financial_accounts WHERE id = ?' . $this->db->forUpdate(), [$account['id']]);
                $balance = $this->totals($order, $biz)['balance'];
                if ($amount->compare($balance) > 0) {
                    throw new InsufficientFunds('El cobro (' . $amount->format() . ') supera lo pendiente del pedido (' . ($balance->isPositive() ? $balance->format() : '0.00') . ').');
                }
                $kind = $order['status'] === 'delivered' ? 'payment' : 'deposit';
                $txId = $this->finance->post($biz, (int) $account['id'], 'in', $amount, $nowUtc, 'order_payment', ($kind === 'deposit' ? 'Anticipo ' : 'Cobro ') . $order['order_number'], $order['order_number'], $actor);
                $payId = $this->db->insert('INSERT INTO order_payments (business_id, order_id, financial_transaction_id, kind, notes) VALUES (?, ?, ?, ?, ?)', [$biz, $id, $txId, $kind, $notes === '' ? null : $notes]);
                if ($tokenHash !== null) {
                    $this->db->insert("INSERT INTO idempotency_keys (business_id, user_id, scope, token, result_id) VALUES (?, ?, 'order_pay', ?, ?)", [$biz, $actor['id'], $tokenHash, $payId]);
                }

                return $payId;
            });
        } catch (InsufficientFunds $e) {
            return ['ok' => false, 'errors' => ['amount' => $e->getMessage()], 'id' => null, 'status' => 422];
        } catch (PDOException $e) {
            if ($tokenHash !== null && $this->db->first("SELECT id FROM idempotency_keys WHERE business_id = ? AND scope = 'order_pay' AND token = ?", [$biz, $tokenHash]) !== null) {
                return ['ok' => true, 'errors' => [], 'id' => null, 'status' => 200, 'duplicate' => true]; // carrera: ya se registró
            }
            throw $e;
        }

        return ['ok' => true, 'errors' => [], 'id' => $payId, 'status' => 200];
    }

    /**
     * Reembolso al cliente (sale dinero). No puede superar lo neto recibido.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in account_id, amount, notes
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function refund(array $actor, int $id, array $in, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        if ($order['status'] === 'draft') {
            return ['ok' => false, 'errors' => ['amount' => 'Un borrador no tiene cobros.'], 'id' => null, 'status' => 422];
        }
        $errors = [];
        $account = $this->finance->account($biz, (int) ($in['account_id'] ?? 0));
        if ($account === null || (int) $account['is_active'] !== 1 || $account['currency'] !== 'USD') {
            $errors['account_id'] = 'Selecciona una cuenta activa en moneda base (USD).';
        }
        $amount = Decimal::fromInput($in['amount'] ?? '', 2);
        if ($amount === null || !$amount->isPositive()) {
            $errors['amount'] = 'El importe debe ser mayor que 0.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }
        $notes = mb_substr(trim((string) ($in['notes'] ?? '')), 0, 255);

        try {
            $payId = $this->db->transaction(function () use ($actor, $biz, $id, $account, $amount, $notes, $nowUtc): int {
                $order = $this->lockOrder($biz, $id) ?? throw new \RuntimeException('Pedido no encontrado.');
                $this->db->value('SELECT id FROM financial_accounts WHERE id = ?' . $this->db->forUpdate(), [$account['id']]);
                $netPaid = $this->totals($order, $biz)['net_paid'];
                if ($amount->compare($netPaid) > 0) {
                    throw new InsufficientFunds('El reembolso (' . $amount->format() . ') supera lo recibido neto (' . ($netPaid->isPositive() ? $netPaid->format() : '0.00') . ').');
                }
                $txId = $this->finance->post($biz, (int) $account['id'], 'out', $amount, $nowUtc, 'order_payment', 'Reembolso ' . $order['order_number'], $order['order_number'], $actor);

                return $this->db->insert("INSERT INTO order_payments (business_id, order_id, financial_transaction_id, kind, notes) VALUES (?, ?, ?, 'refund', ?)", [$biz, $id, $txId, $notes === '' ? null : $notes]);
            });
        } catch (InsufficientFunds $e) {
            return ['ok' => false, 'errors' => ['amount' => $e->getMessage()], 'id' => null, 'status' => 422];
        }

        return ['ok' => true, 'errors' => [], 'id' => $payId, 'status' => 200];
    }

    /** @param array<string,mixed> $actor @return array{ok:bool,error:?string,status:int} */
    public function voidPayment(array $actor, int $id, int $paymentId, string $reason, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $pay = $this->db->first('SELECT * FROM order_payments WHERE id = ? AND order_id = ? AND business_id = ?', [$paymentId, $id, $biz]);
        $order = $this->find($biz, $id);
        if ($pay === null || $order === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        if ($pay['kind'] !== 'refund') { // anular un cobro no puede dejar reembolsos sin respaldo
            $amount = Decimal::from($this->db->value('SELECT amount FROM financial_transactions WHERE id = ?', [$pay['financial_transaction_id']]), 2);
            if ($this->totals($order, $biz)['net_paid']->sub($amount)->isNegative()) {
                return ['ok' => false, 'error' => 'Primero anula los reembolsos que dependen de este cobro.', 'status' => 422];
            }
        }
        $r = $this->finance->void($actor, (int) $pay['financial_transaction_id'], $reason, $nowUtc, true);
        if ($r['ok']) {
            $this->log($actor, 'order.payment_void', $id, null, ['payment_id' => $paymentId, 'reason' => trim($reason)]);
        }

        return $r;
    }

    // ---------- devoluciones ----------

    /**
     * Devolución de un pedido entregado. Importes proporcionales (la última devolución de una línea toma el resto
     * para que la suma cuadre exacta). Con restock, repone stock al costo congelado en la entrega.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in qty[lineId], reason, restock, returned_on (Y-m-d)
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function createReturn(array $actor, int $id, array $in, string $today, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        if ($order['status'] !== 'delivered') {
            return ['ok' => false, 'errors' => ['status' => 'Solo se devuelven pedidos entregados.'], 'id' => null, 'status' => 422];
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        $errors = [];
        if ($reason === '' || mb_strlen($reason) > 255) {
            $errors['reason'] = 'Indica el motivo (máx. 255 caracteres).';
        }
        $on = (string) ($in['returned_on'] ?? '') ?: $today;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        if ($d === false || $d->format('Y-m-d') !== $on) {
            $errors['returned_on'] = 'Fecha no válida.';
        }
        $requested = [];
        foreach ((array) ($in['qty'] ?? []) as $lineId => $raw) {
            if (trim((string) $raw) === '') {
                continue;
            }
            $q = Decimal::fromInput($raw, 3);
            if ($q === null || !$q->isPositive()) {
                $errors['qty'] = 'Las cantidades deben ser mayores que 0.';
                continue;
            }
            $requested[(int) $lineId] = $q;
        }
        if ($requested === [] && !isset($errors['qty'])) {
            $errors['qty'] = 'Indica al menos una cantidad a devolver.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }
        $restock = !empty($in['restock']) ? 1 : 0;

        try {
            $retId = $this->db->transaction(function () use ($actor, $biz, $id, $order, $requested, $reason, $on, $restock, $nowUtc): int {
                $this->lockOrder($biz, $id);
                $lines = [];
                foreach ($this->lines($biz, $id) as $l) {
                    $lines[(int) $l['id']] = $l;
                }
                ksort($requested);
                foreach ($requested as $lineId => $q) {
                    $l = $lines[$lineId] ?? throw new \DomainException('Línea no válida.');
                    $left = Decimal::from($l['quantity'], 3)->sub(Decimal::from($l['returned_quantity'], 3));
                    if ($q->compare($left) > 0) {
                        throw new \DomainException('«' . $l['product_name'] . '»: solo quedan ' . $left->format() . ' por devolver.');
                    }
                }
                $retId = $this->db->insert('INSERT INTO order_returns (business_id, order_id, returned_on, reason, restock, created_by) VALUES (?, ?, ?, ?, ?, ?)', [$biz, $id, $on, $reason, $restock, $actor['id']]);
                $totalReturned = Decimal::zero();
                foreach ($requested as $lineId => $q) {
                    $l = $lines[$lineId];
                    $lineQty = Decimal::from($l['quantity'], 3);
                    $left = $lineQty->sub(Decimal::from($l['returned_quantity'], 3));
                    if ($q->equals($left)) { // última devolución de la línea: el resto exacto
                        $prev = $this->db->first('SELECT COALESCE(SUM(net_amount), 0) AS n, COALESCE(SUM(tax_amount), 0) AS t FROM order_return_lines WHERE order_line_id = ?', [$lineId]);
                        $net = Decimal::from($l['net_amount'], 2)->sub(Decimal::from($prev['n'], 2));
                        $tax = Decimal::from($l['tax_amount'], 2)->sub(Decimal::from($prev['t'], 2));
                    } else {
                        $net = Decimal::from($l['net_amount'], 2)->mul($q, 6)->div($lineQty, 2);
                        $tax = Decimal::from($l['tax_amount'], 2)->mul($q, 6)->div($lineQty, 2);
                    }
                    $cost = $l['unit_cost_snapshot'] === null ? null : Decimal::from($l['unit_cost_snapshot'], 4);
                    $moveId = null;
                    if ($restock === 1 && $cost !== null) {
                        $moveId = $this->inventory->move($actor, (int) $order['warehouse_id'], (int) $l['variant_id'], 'return', $q, $cost, 'Devolución ' . $order['order_number'], 'order_return', $retId, $nowUtc);
                    }
                    $this->db->insert(
                        'INSERT INTO order_return_lines (business_id, return_id, order_line_id, quantity, net_amount, tax_amount, line_total, unit_cost, stock_movement_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$biz, $retId, $lineId, $q->toString(), $net->toString(), $tax->toString(), $net->add($tax)->toString(), $cost?->toString(), $moveId]
                    );
                    $this->db->execute('UPDATE order_lines SET returned_quantity = ? WHERE id = ?', [Decimal::from($l['returned_quantity'], 3)->add($q)->toString(), $lineId]);
                    $totalReturned = $totalReturned->add($net)->add($tax);
                }
                $this->log($actor, 'order.return', $id, null, ['return_id' => $retId, 'total' => $totalReturned->toString(), 'restock' => $restock, 'reason' => $reason]);

                return $retId;
            });
        } catch (\DomainException $e) {
            return ['ok' => false, 'errors' => ['qty' => $e->getMessage()], 'id' => null, 'status' => 422];
        }

        return ['ok' => true, 'errors' => [], 'id' => $retId, 'status' => 200];
    }

    // ---------- conciliación ----------

    /** @return list<string> */
    public function reconcile(int $biz): array
    {
        $issues = [];
        $n = (int) $this->db->value("SELECT COUNT(*) FROM orders WHERE business_id = ? AND status = 'delivered' AND delivered_at IS NULL", [$biz]);
        if ($n > 0) {
            $issues[] = "{$n} pedido(s) entregado(s) sin fecha de entrega.";
        }
        $n = (int) $this->db->value("SELECT COUNT(*) FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE l.business_id = ? AND o.status = 'delivered' AND l.unit_cost_snapshot IS NULL", [$biz]);
        if ($n > 0) {
            $issues[] = "{$n} línea(s) entregada(s) sin costo congelado.";
        }
        $n = (int) $this->db->value("SELECT COUNT(*) FROM order_lines WHERE business_id = ? AND returned_quantity > quantity", [$biz]);
        if ($n > 0) {
            $issues[] = "{$n} línea(s) con más devuelto que vendido.";
        }
        $n = (int) $this->db->value("SELECT COUNT(*) FROM stock_reservations r JOIN orders o ON o.id = (SELECT order_id FROM order_lines WHERE id = r.order_line_id) WHERE r.business_id = ? AND r.status = 'active' AND o.status IN ('delivered', 'cancelled')", [$biz]);
        if ($n > 0) {
            $issues[] = "{$n} reserva(s) activa(s) en pedidos cerrados.";
        }

        return $issues;
    }

    // ---------- internos ----------

    /** @return array<string,mixed>|null */
    private function lockOrder(int $biz, int $id): ?array
    {
        return $this->db->first('SELECT * FROM orders WHERE id = ? AND business_id = ?' . $this->db->forUpdate(), [$id, $biz]);
    }

    private function recordStatus(int $biz, int $id, ?string $from, string $to, ?string $note, int $userId, string $nowUtc): void
    {
        $this->db->execute('INSERT INTO order_status_history (business_id, order_id, from_status, to_status, note, changed_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$biz, $id, $from, $to, $note, $userId, $nowUtc]);
    }

    /** @param array<string,mixed> $actor @param array<string,mixed>|null $old @param array<string,mixed>|null $new */
    private function log(array $actor, string $action, int $id, ?array $old, ?array $new): void
    {
        $this->audit->log((int) $actor['business_id'], (int) $actor['id'], $action, 'orders', $id, $old, $new, $actor['ip'] ?? null, $actor['ua'] ?? null);
    }
}
