<?php

declare(strict_types=1);

namespace Bic\Modules\Purchases;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use Bic\Core\Money\TaxCalculator;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Finance\InsufficientFunds;
use Bic\Modules\Inventory\InsufficientStock;
use Bic\Modules\Inventory\InventoryService;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;

/**
 * Compras: orden → recepciones → pagos. Compra = inventario (activo). Los costos de llegada y el impuesto no
 * recuperable se capitalizan al costo de lo recibido; el costo promedio se recalcula en cada recepción.
 */
final class PurchaseService
{
    public const STATUSES = ['draft' => 'Borrador', 'ordered' => 'Pedida', 'partial' => 'Recibida parcial', 'received' => 'Recibida', 'cancelled' => 'Cancelada'];
    public const COST_KINDS = ['freight' => 'Flete', 'duties' => 'Aranceles', 'insurance' => 'Seguro', 'other' => 'Otro costo de llegada'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly InventoryService $inventory,
        private readonly FinanceService $finance,
    ) {
    }

    // ---------- lectura ----------

    /** @return array<string,mixed>|null */
    public function find(int $biz, int $id): ?array
    {
        return $this->db->first(
            'SELECT po.*, s.name AS supplier_name, w.name AS warehouse_name FROM purchase_orders po
             JOIN suppliers s ON s.id = po.supplier_id JOIN warehouses w ON w.id = po.warehouse_id WHERE po.id = ? AND po.business_id = ?',
            [$id, $biz]
        );
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $biz, int $id): array
    {
        return $this->db->select(
            'SELECT l.*, v.sku, v.name AS variant_name, p.name AS product_name FROM purchase_order_lines l
             JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
             WHERE l.purchase_order_id = ? AND l.business_id = ? ORDER BY l.id',
            [$id, $biz]
        );
    }

    /** @return list<array<string,mixed>> */
    public function costLines(int $biz, int $id): array
    {
        return $this->db->select('SELECT * FROM purchase_cost_lines WHERE purchase_order_id = ? AND business_id = ? ORDER BY id', [$id, $biz]);
    }

    /** @return list<array<string,mixed>> */
    public function receipts(int $biz, int $id): array
    {
        $receipts = $this->db->select('SELECT * FROM purchase_receipts WHERE purchase_order_id = ? AND business_id = ? ORDER BY id', [$id, $biz]);
        foreach ($receipts as &$r) {
            $r['lines'] = $this->db->select(
                'SELECT rl.*, v.sku, p.name AS product_name FROM purchase_receipt_lines rl JOIN product_variants v ON v.id = rl.variant_id JOIN products p ON p.id = v.product_id WHERE rl.receipt_id = ? ORDER BY rl.id',
                [$r['id']]
            );
        }
        unset($r);

        return $receipts;
    }

    /** @return list<array<string,mixed>> pagos con importe y estado del asiento */
    public function payments(int $biz, int $id): array
    {
        return $this->db->select(
            'SELECT pp.id, pp.notes, pp.financial_transaction_id, t.amount, t.occurred_at, t.voided_at, t.void_reason, a.name AS account_name
             FROM purchase_payments pp JOIN financial_transactions t ON t.id = pp.financial_transaction_id JOIN financial_accounts a ON a.id = t.account_id
             WHERE pp.purchase_order_id = ? AND pp.business_id = ? ORDER BY pp.id',
            [$id, $biz]
        );
    }

    /**
     * Totales calculados (nada se guarda duplicado). Todo en moneda base salvo subtotal/impuesto/total del documento.
     *
     * @param array<string,mixed> $order
     * @return array<string,Decimal>
     */
    public function totals(array $order, int $biz): array
    {
        $id = (int) $order['id'];
        $sub = Decimal::zero();
        $tax = Decimal::zero();
        foreach ($this->lines($biz, $id) as $l) {
            $sub = $sub->add(Decimal::from($l['line_subtotal'], 2));
            $tax = $tax->add(Decimal::from($l['tax_amount'], 2));
        }
        $rate = Decimal::from($order['exchange_rate'], 8);
        $goods = $sub->add($tax)->mul($rate, 2);
        $costs = Decimal::zero();
        foreach ($this->costLines($biz, $id) as $c) {
            $costs = $costs->add(Decimal::from($c['amount'], 2));
        }
        $paid = Decimal::zero();
        foreach ($this->payments($biz, $id) as $p) {
            if ($p['voided_at'] === null) {
                $paid = $paid->add(Decimal::from($p['amount'], 2));
            }
        }
        $payable = $goods->add($costs);

        return ['subtotal' => $sub, 'tax' => $tax, 'total_doc' => $sub->add($tax), 'goods_base' => $goods, 'costs_base' => $costs, 'payable' => $payable, 'paid' => $paid, 'due' => $payable->sub($paid)];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $biz, string $q, string $status, int $limit, int $offset): array
    {
        $where = ['po.business_id = ?'];
        $params = [$biz];
        if ($q !== '') {
            $like = \Bic\Core\Paginator::like($q);
            $where[] = "(po.po_number LIKE ? ESCAPE '!' OR s.name LIKE ? ESCAPE '!')";
            array_push($params, $like, $like);
        }
        if (isset(self::STATUSES[$status])) {
            $where[] = 'po.status = ?';
            $params[] = $status;
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE {$w}", $params);
        $rows = $this->db->select(
            "SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE {$w} ORDER BY po.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        foreach ($rows as &$r) {
            $t = $this->totals($r, $biz);
            $r['payable'] = $t['payable']->toString();
            $r['due'] = $t['due']->toString();
        }
        unset($r);

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function supplierOptions(int $biz): array
    {
        return $this->db->select('SELECT id, name, currency FROM suppliers WHERE business_id = ? AND is_active = 1 ORDER BY name', [$biz]);
    }

    // ---------- escritura de la orden ----------

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in supplier_id, warehouse_id, currency, exchange_rate, expected_on, notes
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
            return ['ok' => false, 'errors' => ['status' => 'Solo se puede editar una orden en borrador.'], 'id' => $id, 'status' => 422];
        }
        $errors = [];
        $supplier = $this->db->first('SELECT * FROM suppliers WHERE id = ? AND business_id = ? AND is_active = 1', [(int) ($in['supplier_id'] ?? 0), $biz]);
        if ($supplier === null) {
            $errors['supplier_id'] = 'Selecciona un proveedor activo.';
        }
        if ($this->inventory->activeWarehouse($biz, (int) ($in['warehouse_id'] ?? 0)) === null) {
            $errors['warehouse_id'] = 'Selecciona un almacén activo.';
        }
        $base = (string) $this->db->value('SELECT base_currency FROM businesses WHERE id = ?', [$biz]);
        $currency = strtoupper(trim((string) ($in['currency'] ?? '')) ?: (string) ($supplier['currency'] ?? $base));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = 'Moneda no válida.';
        }
        $rate = $currency === $base ? Decimal::from(1, 8) : Decimal::fromInput($in['exchange_rate'] ?? '', 8);
        if ($rate === null || !$rate->isPositive()) {
            $errors['exchange_rate'] = "Indica el tipo de cambio a {$base} (mayor que 0).";
        }
        $expected = trim((string) ($in['expected_on'] ?? ''));
        if ($expected !== '' && !$this->isDate($expected)) {
            $errors['expected_on'] = 'Fecha no válida.';
        }
        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > 500) {
            $errors['notes'] = 'Máximo 500 caracteres.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $id, 'status' => 422];
        }
        $v = [
            'supplier_id' => (int) $supplier['id'], 'warehouse_id' => (int) $in['warehouse_id'], 'currency' => $currency, 'exchange_rate' => $rate->toString(),
            'expected_on' => $expected === '' ? null : $expected, 'notes' => $notes === '' ? null : $notes,
        ];

        $newId = $this->db->transaction(function () use ($biz, $id, $v, $old, $actor): int {
            if ($id === null) {
                $newId = $this->db->insert(
                    'INSERT INTO purchase_orders (business_id, po_number, supplier_id, warehouse_id, status, currency, exchange_rate, expected_on, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$biz, 'TMP' . bin2hex(random_bytes(8)), $v['supplier_id'], $v['warehouse_id'], 'draft', $v['currency'], $v['exchange_rate'], $v['expected_on'], $v['notes'], $actor['id']]
                );
                $this->db->execute('UPDATE purchase_orders SET po_number = ? WHERE id = ?', [sprintf('OC-%05d', $newId), $newId]);
            } else {
                $this->db->execute(
                    'UPDATE purchase_orders SET supplier_id = ?, warehouse_id = ?, currency = ?, exchange_rate = ?, expected_on = ?, notes = ? WHERE id = ? AND business_id = ?',
                    [$v['supplier_id'], $v['warehouse_id'], $v['currency'], $v['exchange_rate'], $v['expected_on'], $v['notes'], $id, $biz]
                );
                $newId = $id;
            }
            $this->log($actor, $id === null ? 'purchase.create' : 'purchase.update', $newId, $old === null ? null : array_intersect_key($old, $v), $v);

            return $newId;
        });

        return ['ok' => true, 'errors' => [], 'id' => $newId, 'status' => 200];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in variant_id, quantity, unit_price (vacío = última cotización en la misma moneda), tax_rate (vacío = la del producto), tax_recoverable
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
            return ['ok' => false, 'errors' => ['status' => 'Solo se pueden cambiar líneas en una orden en borrador.'], 'status' => 422];
        }
        $errors = [];
        $variant = $this->db->first(
            'SELECT v.id, p.id AS product_id, t.rate AS tax_rate FROM product_variants v JOIN products p ON p.id = v.product_id JOIN tax_rates t ON t.id = p.tax_rate_id
             WHERE v.id = ? AND v.business_id = ? AND v.is_active = 1',
            [(int) ($in['variant_id'] ?? 0), $biz]
        );
        if ($variant === null) {
            $errors['variant_id'] = 'Selecciona una variante activa.';
        } elseif ((int) $this->db->value('SELECT COUNT(*) FROM purchase_order_lines WHERE purchase_order_id = ? AND variant_id = ?', [$id, $variant['id']]) > 0) {
            $errors['variant_id'] = 'Esa variante ya está en la orden: elimina la línea para cambiarla.';
        }
        $qty = Decimal::fromInput($in['quantity'] ?? '', 3);
        if ($qty === null || !$qty->isPositive()) {
            $errors['quantity'] = 'La cantidad debe ser mayor que 0.';
        }
        $price = null;
        $rawPrice = trim((string) ($in['unit_price'] ?? ''));
        if ($rawPrice === '' && $variant !== null) {
            $q = $this->db->first(
                'SELECT sp.unit_price FROM supplier_prices sp JOIN product_suppliers ps ON ps.id = sp.product_supplier_id
                 WHERE ps.product_id = ? AND ps.supplier_id = ? AND sp.currency = ? ORDER BY sp.quoted_on DESC, sp.id DESC LIMIT 1',
                [$variant['product_id'], $order['supplier_id'], $order['currency']]
            );
            $rawPrice = $q === null ? '' : (string) $q['unit_price'];
        }
        if ($rawPrice === '') {
            $errors['unit_price'] = 'Indica el precio unitario (no hay cotización en esta moneda).';
        } else {
            $price = Decimal::fromInput($rawPrice, 4);
            if ($price === null || $price->isNegative()) {
                $errors['unit_price'] = 'El precio debe ser un número mayor o igual a 0.';
            }
        }
        $rawTax = trim((string) ($in['tax_rate'] ?? ''));
        $rate = $rawTax === '' ? ($variant !== null ? Decimal::from($variant['tax_rate'], 4) : null) : Decimal::fromInput($rawTax, 4);
        if ($rate === null || $rate->isNegative() || $rate->compare(Decimal::from(100, 4)) > 0) {
            $errors['tax_rate'] = 'La tasa debe estar entre 0 y 100.';
        }
        if ($errors === []) {
            try {
                $calc = TaxCalculator::line($price, $qty, $rate, false);
            } catch (OverflowException | InvalidArgumentException) {
                $errors['quantity'] = 'Los importes son demasiado grandes.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'status' => 422];
        }

        $this->db->transaction(function () use ($biz, $id, $variant, $qty, $price, $rate, $calc, $in, $actor): void {
            $lineId = $this->db->insert(
                'INSERT INTO purchase_order_lines (business_id, purchase_order_id, variant_id, quantity, unit_price, tax_rate, tax_recoverable, line_subtotal, tax_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$biz, $id, $variant['id'], $qty->toString(), $price->toString(), $rate->toString(), !empty($in['tax_recoverable']) ? 1 : 0, $calc['net']->toString(), $calc['tax']->toString()]
            );
            $this->log($actor, 'purchase.line_add', $id, null, ['line_id' => $lineId, 'variant_id' => $variant['id'], 'quantity' => $qty->toString(), 'unit_price' => $price->toString()]);
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
            return ['ok' => false, 'error' => 'Solo se pueden cambiar líneas en una orden en borrador.', 'status' => 422];
        }
        $n = $this->db->execute('DELETE FROM purchase_order_lines WHERE id = ? AND purchase_order_id = ? AND business_id = ?', [$lineId, $id, $biz]);
        if ($n === 0) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        $this->log($actor, 'purchase.line_remove', $id, ['line_id' => $lineId], null);

        return ['ok' => true, 'error' => null, 'status' => 200];
    }

    /**
     * Costo de llegada (flete, aranceles…) en moneda base. Solo mientras no haya recepciones, para que el reparto sea coherente.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in
     * @return array{ok:bool,errors:array<string,string>,status:int}
     */
    public function addCostLine(array $actor, int $id, array $in): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'errors' => [], 'status' => 404];
        }
        if (!$this->costsEditable($order)) {
            return ['ok' => false, 'errors' => ['kind' => 'Los costos de llegada solo se editan antes de la primera recepción.'], 'status' => 422];
        }
        $errors = [];
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::COST_KINDS[$kind])) {
            $errors['kind'] = 'Tipo de costo no válido.';
        }
        $amount = Decimal::fromInput($in['amount'] ?? '', 2);
        if ($amount === null || !$amount->isPositive()) {
            $errors['amount'] = 'El importe debe ser mayor que 0.';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) > 150) {
            $errors['description'] = 'Máximo 150 caracteres.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'status' => 422];
        }
        $cid = $this->db->insert('INSERT INTO purchase_cost_lines (business_id, purchase_order_id, kind, description, amount) VALUES (?, ?, ?, ?, ?)', [$biz, $id, $kind, $desc === '' ? null : $desc, $amount->toString()]);
        $this->log($actor, 'purchase.cost_add', $id, null, ['cost_line_id' => $cid, 'kind' => $kind, 'amount' => $amount->toString()]);

        return ['ok' => true, 'errors' => [], 'status' => 200];
    }

    /** @param array<string,mixed> $actor @return array{ok:bool,error:?string,status:int} */
    public function removeCostLine(array $actor, int $id, int $costId): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        if (!$this->costsEditable($order)) {
            return ['ok' => false, 'error' => 'Los costos de llegada solo se editan antes de la primera recepción.', 'status' => 422];
        }
        if ($this->db->execute('DELETE FROM purchase_cost_lines WHERE id = ? AND purchase_order_id = ? AND business_id = ?', [$costId, $id, $biz]) === 0) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        $this->log($actor, 'purchase.cost_remove', $id, ['cost_line_id' => $costId], null);

        return ['ok' => true, 'error' => null, 'status' => 200];
    }

    /** @param array<string,mixed> $order */
    private function costsEditable(array $order): bool
    {
        return in_array($order['status'], ['draft', 'ordered'], true)
            && (int) $this->db->value('SELECT COUNT(*) FROM purchase_receipts WHERE purchase_order_id = ?', [$order['id']]) === 0;
    }

    /** @param array<string,mixed> $actor @return array{ok:bool,error:?string,status:int} */
    public function place(array $actor, int $id, string $today): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        if ($order['status'] !== 'draft') {
            return ['ok' => false, 'error' => 'Solo se puede enviar una orden en borrador.', 'status' => 422];
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM purchase_order_lines WHERE purchase_order_id = ?', [$id]) === 0) {
            return ['ok' => false, 'error' => 'Agrega al menos una línea antes de enviar la orden.', 'status' => 422];
        }
        $this->db->execute("UPDATE purchase_orders SET status = 'ordered', ordered_on = ? WHERE id = ? AND business_id = ? AND status = 'draft'", [$today, $id, $biz]);
        $this->log($actor, 'purchase.place', $id, ['status' => 'draft'], ['status' => 'ordered']);

        return ['ok' => true, 'error' => null, 'status' => 200];
    }

    /** @param array<string,mixed> $actor @return array{ok:bool,error:?string,status:int} */
    public function cancel(array $actor, int $id, string $reason): array
    {
        $biz = (int) $actor['business_id'];
        $reason = trim($reason);
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        if (!in_array($order['status'], ['draft', 'ordered'], true) || (int) $this->db->value('SELECT COUNT(*) FROM purchase_receipts WHERE purchase_order_id = ?', [$id]) > 0) {
            return ['ok' => false, 'error' => 'Una orden con mercancía recibida no se cancela.', 'status' => 422];
        }
        if ($reason === '' || mb_strlen($reason) > 255) {
            return ['ok' => false, 'error' => 'Indica el motivo de la cancelación.', 'status' => 422];
        }
        if (!$this->totals($order, $biz)['paid']->isZero()) {
            return ['ok' => false, 'error' => 'Anula primero los pagos registrados para poder cancelar.', 'status' => 422];
        }
        $this->db->execute("UPDATE purchase_orders SET status = 'cancelled', cancel_reason = ? WHERE id = ? AND business_id = ?", [$reason, $id, $biz]);
        $this->log($actor, 'purchase.cancel', $id, ['status' => $order['status']], ['status' => 'cancelled', 'reason' => $reason]);

        return ['ok' => true, 'error' => null, 'status' => 200];
    }

    // ---------- recepción ----------

    /**
     * Costo puesto en almacén por unidad (moneda base) = precio × tipo de cambio + impuesto no recuperable
     * + parte de los costos de llegada proporcional al valor de la línea.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $line
     */
    public function landedUnitCost(array $order, array $line, Decimal $orderValueBase, Decimal $costsBase): Decimal
    {
        $rate = Decimal::from($order['exchange_rate'], 8);
        $unit = Decimal::from($line['unit_price'], 4)->mul($rate, 4);
        $tax = (int) $line['tax_recoverable'] === 1 ? Decimal::zero(4) : $unit->mul(Decimal::from($line['tax_rate'], 4), 4)->div(Decimal::from(100, 4), 4);
        $alloc = $orderValueBase->isZero() ? Decimal::zero(4) : $costsBase->mul($unit, 4)->div($orderValueBase, 4);

        return $unit->add($tax)->add($alloc);
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<int|string,mixed> $quantities [line_id => cantidad]
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function receive(array $actor, int $id, array $quantities, string $receivedOn, string $notes, string $today, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        if (!in_array($order['status'], ['ordered', 'partial'], true)) {
            return ['ok' => false, 'errors' => ['status' => 'Solo se recibe mercancía de una orden pedida o parcialmente recibida.'], 'id' => null, 'status' => 422];
        }
        $errors = [];
        if (!$this->isDate($receivedOn) || $receivedOn > $today) {
            $errors['received_on'] = 'Fecha de recepción no válida (no puede ser futura).';
        }
        $lines = [];
        foreach ($this->lines($biz, $id) as $l) {
            $lines[(int) $l['id']] = $l;
        }
        $toReceive = [];
        foreach ($quantities as $lineId => $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $lineId = (int) $lineId;
            $qty = Decimal::fromInput($raw, 3);
            if (!isset($lines[$lineId])) {
                $errors['line_' . $lineId] = 'Línea no válida.';
            } elseif ($qty === null || $qty->isNegative()) {
                $errors['line_' . $lineId] = 'Cantidad no válida.';
            } elseif ($qty->isPositive()) {
                $pending = Decimal::from($lines[$lineId]['quantity'], 3)->sub(Decimal::from($lines[$lineId]['received_quantity'], 3));
                if ($qty->compare($pending) > 0) {
                    $errors['line_' . $lineId] = 'No puedes recibir más de lo pendiente (' . $pending->format() . ').';
                } else {
                    $toReceive[$lineId] = $qty;
                }
            }
        }
        if ($toReceive === [] && $errors === []) {
            $errors['quantities'] = 'Indica la cantidad recibida de al menos una línea.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }
        $notes = mb_substr(trim($notes), 0, 255);

        try {
            $receiptId = $this->db->transaction(function () use ($actor, $biz, $id, $order, $lines, $toReceive, $receivedOn, $notes, $nowUtc): int {
                $this->db->value('SELECT id FROM purchase_orders WHERE id = ? AND business_id = ?' . $this->db->forUpdate(), [$id, $biz]);
                $fresh = $this->find($biz, $id);
                if (!in_array($fresh['status'], ['ordered', 'partial'], true)) {
                    throw new InvalidArgumentException('La orden cambió de estado; vuelve a intentarlo.');
                }
                $orderValue = Decimal::zero(4);
                foreach ($lines as $l) {
                    $orderValue = $orderValue->add(Decimal::from($l['quantity'], 3)->mul(Decimal::from($l['unit_price'], 4), 4)->mul(Decimal::from($order['exchange_rate'], 8), 4));
                }
                $costs = $this->totals($order, $biz)['costs_base'];
                $receiptId = $this->db->insert('INSERT INTO purchase_receipts (business_id, purchase_order_id, received_on, notes, created_by) VALUES (?, ?, ?, ?, ?)', [$biz, $id, $receivedOn, $notes === '' ? null : $notes, $actor['id']]);

                ksort($toReceive);
                foreach ($toReceive as $lineId => $qty) {
                    $line = $lines[$lineId];
                    $locked = $this->db->first('SELECT received_quantity, quantity FROM purchase_order_lines WHERE id = ?' . $this->db->forUpdate(), [$lineId]);
                    if ($qty->compare(Decimal::from($locked['quantity'], 3)->sub(Decimal::from($locked['received_quantity'], 3))) > 0) {
                        throw new InvalidArgumentException('Cantidad pendiente insuficiente; vuelve a intentarlo.');
                    }
                    $unitCost = $this->landedUnitCost($order, $line, $orderValue, $costs);
                    $moveId = $this->inventory->move($actor, (int) $order['warehouse_id'], (int) $line['variant_id'], 'purchase_receipt', $qty, $unitCost, $order['po_number'], 'purchase_receipt', $receiptId, $nowUtc);
                    $this->db->execute('INSERT INTO purchase_receipt_lines (business_id, receipt_id, order_line_id, variant_id, quantity, unit_cost, stock_movement_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$biz, $receiptId, $lineId, $line['variant_id'], $qty->toString(), $unitCost->toString(), $moveId]);
                    $this->db->execute('UPDATE purchase_order_lines SET received_quantity = ? WHERE id = ?', [Decimal::from($locked['received_quantity'], 3)->add($qty)->toString(), $lineId]);
                }
                $pending = 0;
                foreach ($this->lines($biz, $id) as $l) {
                    if (Decimal::from($l['received_quantity'], 3)->compare(Decimal::from($l['quantity'], 3)) < 0) {
                        $pending++;
                    }
                }
                $status = $pending === 0 ? 'received' : 'partial';
                $this->db->execute('UPDATE purchase_orders SET status = ? WHERE id = ?', [$status, $id]);
                $this->log($actor, 'purchase.receive', $id, ['status' => $order['status']], ['status' => $status, 'receipt_id' => $receiptId]);

                return $receiptId;
            });
        } catch (InvalidArgumentException | OverflowException | InsufficientStock $e) {
            return ['ok' => false, 'errors' => ['quantities' => $e->getMessage()], 'id' => null, 'status' => 422];
        }

        return ['ok' => true, 'errors' => [], 'id' => $receiptId, 'status' => 200];
    }

    // ---------- pagos ----------

    /**
     * Pago a proveedor en moneda base desde una cuenta. No puede superar lo pendiente.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in account_id, amount, notes
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function pay(array $actor, int $id, array $in, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $order = $this->find($biz, $id);
        if ($order === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        if (in_array($order['status'], ['draft', 'cancelled'], true)) {
            return ['ok' => false, 'errors' => ['amount' => 'Solo se registran pagos de órdenes enviadas.'], 'id' => null, 'status' => 422];
        }
        $errors = [];
        $account = $this->finance->account($biz, (int) ($in['account_id'] ?? 0));
        if ($account === null || (int) $account['is_active'] !== 1) {
            $errors['account_id'] = 'Selecciona una cuenta activa.';
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
            $payId = $this->db->transaction(function () use ($actor, $biz, $id, $order, $account, $amount, $notes, $nowUtc): int {
                $this->db->value('SELECT id FROM purchase_orders WHERE id = ? AND business_id = ?' . $this->db->forUpdate(), [$id, $biz]);
                $this->db->value('SELECT id FROM financial_accounts WHERE id = ?' . $this->db->forUpdate(), [$account['id']]);
                $due = $this->totals($order, $biz)['due'];
                if ($amount->compare($due) > 0) {
                    throw new InsufficientFunds('El pago (' . $amount->format() . ') supera lo pendiente de la orden (' . $due->format() . ').');
                }
                $txId = $this->finance->post($biz, (int) $account['id'], 'out', $amount, $nowUtc, 'purchase_payment', 'Pago ' . $order['po_number'] . ' — ' . $order['supplier_name'], $order['po_number'], $actor);

                return $this->db->insert('INSERT INTO purchase_payments (business_id, purchase_order_id, financial_transaction_id, notes) VALUES (?, ?, ?, ?)', [$biz, $id, $txId, $notes === '' ? null : $notes]);
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
        $pay = $this->db->first('SELECT * FROM purchase_payments WHERE id = ? AND purchase_order_id = ? AND business_id = ?', [$paymentId, $id, $biz]);
        if ($pay === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        $r = $this->finance->void($actor, (int) $pay['financial_transaction_id'], $reason, $nowUtc, true);
        if ($r['ok']) {
            $this->log($actor, 'purchase.payment_void', $id, null, ['payment_id' => $paymentId, 'reason' => trim($reason)]);
        }

        return $r;
    }

    private function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /** @param array<string,mixed> $actor @param array<string,mixed>|null $old @param array<string,mixed>|null $new */
    private function log(array $actor, string $action, int $id, ?array $old, ?array $new): void
    {
        $this->audit->log((int) $actor['business_id'], (int) $actor['id'], $action, 'purchase_orders', $id, $old, $new, $actor['ip'] ?? null, $actor['ua'] ?? null);
    }
}
