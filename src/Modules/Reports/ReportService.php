<?php

declare(strict_types=1);

namespace Bic\Modules\Reports;

use Bic\Core\Database\Database;
use Bic\Core\Export\Table;
use Bic\Core\Money\Decimal;
use Bic\Modules\Expenses\ExpenseService;

/** Reportes tabulares: la misma tabla alimenta la pantalla, el CSV, el Excel y el PDF. Moneda base. */
final class ReportService
{
    /** @var array<string,array{label:string,desc:string,permission:string,cost:bool,ranged:bool}> */
    public const REPORTS = [
        'sales'       => ['label' => 'Ventas por pedido', 'desc' => 'Pedidos entregados en el período: base, impuesto, total y devoluciones.', 'permission' => 'sales.view', 'cost' => false, 'ranged' => true],
        'products'    => ['label' => 'Rentabilidad por producto', 'desc' => 'Unidades, ventas, costo, utilidad y margen de cada producto.', 'permission' => 'sales.view', 'cost' => true, 'ranged' => true],
        'receivables' => ['label' => 'Cuentas por cobrar', 'desc' => 'Pedidos entregados con saldo pendiente de cobro.', 'permission' => 'sales.view', 'cost' => false, 'ranged' => false],
        'inventory'   => ['label' => 'Valoración de inventario', 'desc' => 'Existencias por almacén valoradas a costo promedio.', 'permission' => 'inventory.view', 'cost' => true, 'ranged' => false],
        'expenses'    => ['label' => 'Gastos', 'desc' => 'Gastos vigentes del período por fecha y categoría.', 'permission' => 'expenses.view', 'cost' => false, 'ranged' => true],
        'pnl'         => ['label' => 'Estado de resultados', 'desc' => 'Ingresos, costo, ganancia bruta, gastos y ganancia neta.', 'permission' => 'expenses.view', 'cost' => false, 'ranged' => true],
    ];

    public function __construct(private readonly Database $db, private readonly ExpenseService $expenses)
    {
    }

    /**
     * @param callable(string):string $local UTC → fecha/hora local
     * @param array{from:string,to:string,fromUtc:string,toUtc:string,canCost:bool,business:string} $ctx
     */
    public function build(string $key, int $biz, array $ctx, callable $local): ?Table
    {
        $def = self::REPORTS[$key] ?? null;
        if ($def === null) {
            return null;
        }
        $sub = $def['ranged'] ? 'Del ' . $ctx['from'] . ' al ' . $ctx['to'] : 'A la fecha de generación';
        $sub = $ctx['business'] . ' · ' . $sub . ' · moneda base USD';

        return match ($key) {
            'sales' => $this->sales($biz, $ctx, $local, $sub),
            'products' => $this->products($biz, $ctx, $sub),
            'receivables' => $this->receivables($biz, $local, $sub),
            'inventory' => $this->inventory($biz, $sub),
            'expenses' => $this->expenseRows($biz, $ctx, $sub),
            'pnl' => $this->pnl($biz, $ctx, $sub),
        };
    }

    /** @return list<array<string,mixed>> líneas entregadas del período con devoluciones por línea */
    private function lines(int $biz, string $fromUtc, string $toUtc): array
    {
        return $this->db->select(
            "SELECT o.id AS order_id, o.order_number, o.delivered_at, c.name AS customer, p.id AS product_id, p.name AS product, v.sku,
                    l.quantity, l.returned_quantity, l.net_amount, l.tax_amount, l.line_total, l.unit_cost_snapshot,
                    (SELECT COALESCE(SUM(rl.net_amount), 0) FROM order_return_lines rl WHERE rl.order_line_id = l.id) AS ret_net,
                    (SELECT COALESCE(SUM(rl.line_total), 0) FROM order_return_lines rl WHERE rl.order_line_id = l.id) AS ret_total
             FROM order_lines l JOIN orders o ON o.id = l.order_id JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
             LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ? ORDER BY o.delivered_at, o.id, l.id LIMIT 50000",
            [$biz, $fromUtc, $toUtc]
        );
    }

    private function cost(array $l): Decimal
    {
        $kept = Decimal::from($l['quantity'], 3)->sub(Decimal::from($l['returned_quantity'], 3));

        return $kept->mul(Decimal::from($l['unit_cost_snapshot'] ?? 0, 4), 2);
    }

    private function sales(int $biz, array $ctx, callable $local, string $sub): Table
    {
        $orders = [];
        foreach ($this->lines($biz, $ctx['fromUtc'], $ctx['toUtc']) as $l) {
            $o = &$orders[$l['order_id']];
            $o ??= ['order' => $l['order_number'], 'date' => $local((string) $l['delivered_at']), 'customer' => $l['customer'] ?? 'Mostrador', 'net' => Decimal::zero(), 'tax' => Decimal::zero(), 'total' => Decimal::zero(), 'returned' => Decimal::zero(), 'cost' => Decimal::zero(), 'profit' => Decimal::zero()];
            $o['net'] = $o['net']->add(Decimal::from($l['net_amount'], 2));
            $o['tax'] = $o['tax']->add(Decimal::from($l['tax_amount'], 2));
            $o['total'] = $o['total']->add(Decimal::from($l['line_total'], 2));
            $o['returned'] = $o['returned']->add(Decimal::from($l['ret_total'], 2));
            $c = $this->cost($l);
            $o['cost'] = $o['cost']->add($c);
            $o['profit'] = $o['profit']->add(Decimal::from($l['net_amount'], 2)->sub(Decimal::from($l['ret_net'], 2))->sub($c));
            unset($o);
        }
        $cols = [['key' => 'order', 'label' => 'Pedido'], ['key' => 'date', 'label' => 'Entrega'], ['key' => 'customer', 'label' => 'Cliente'],
            ['key' => 'net', 'label' => 'Base', 'type' => 'money'], ['key' => 'tax', 'label' => 'Impuesto', 'type' => 'money'], ['key' => 'total', 'label' => 'Total', 'type' => 'money'], ['key' => 'returned', 'label' => 'Devuelto', 'type' => 'money']];
        if ($ctx['canCost']) {
            $cols[] = ['key' => 'cost', 'label' => 'Costo', 'type' => 'money'];
            $cols[] = ['key' => 'profit', 'label' => 'Utilidad', 'type' => 'money'];
        }

        return $this->finish('Ventas por pedido', $sub, $cols, array_values($orders), ['net', 'tax', 'total', 'returned', 'cost', 'profit'], 'order');
    }

    private function products(int $biz, array $ctx, string $sub): Table
    {
        $by = [];
        foreach ($this->lines($biz, $ctx['fromUtc'], $ctx['toUtc']) as $l) {
            $p = &$by[$l['product_id']];
            $p ??= ['product' => $l['product'], 'qty' => Decimal::zero(), 'net' => Decimal::zero(), 'cost' => Decimal::zero(), 'profit' => Decimal::zero(), 'margin' => null];
            $net = Decimal::from($l['net_amount'], 2)->sub(Decimal::from($l['ret_net'], 2));
            $c = $this->cost($l);
            $p['qty'] = $p['qty']->add(Decimal::from($l['quantity'], 3)->sub(Decimal::from($l['returned_quantity'], 3)));
            $p['net'] = $p['net']->add($net);
            $p['cost'] = $p['cost']->add($c);
            $p['profit'] = $p['profit']->add($net->sub($c));
            unset($p);
        }
        foreach ($by as &$p) {
            $p['margin'] = $p['net']->isPositive() ? $p['profit']->mul(Decimal::from(100, 2), 4)->div($p['net'], 2) : null;
        }
        unset($p);
        usort($by, static fn (array $a, array $b): int => $b['net']->compare($a['net']));
        $cols = [['key' => 'product', 'label' => 'Producto'], ['key' => 'qty', 'label' => 'Unidades', 'type' => 'qty'], ['key' => 'net', 'label' => 'Ventas netas', 'type' => 'money']];
        $sum = ['qty', 'net'];
        if ($ctx['canCost']) {
            array_push($cols, ['key' => 'cost', 'label' => 'Costo', 'type' => 'money'], ['key' => 'profit', 'label' => 'Utilidad', 'type' => 'money'], ['key' => 'margin', 'label' => 'Margen %', 'type' => 'pct']);
            array_push($sum, 'cost', 'profit');
        }

        $t = $this->finish('Rentabilidad por producto', $sub, $cols, array_values($by), $sum, 'product');
        if ($ctx['canCost'] && $t->totals !== null) {
            $net = Decimal::from($t->totals['net'] ?? 0, 2);
            $tot = $t->totals;
            $tot['margin'] = $net->isPositive() ? Decimal::from($t->totals['profit'], 2)->mul(Decimal::from(100, 2), 4)->div($net, 2)->toString() : null;
            $t = new Table($t->title, $t->subtitle, $t->columns, $t->rows, $tot);
        }

        return $t;
    }

    private function receivables(int $biz, callable $local, string $sub): Table
    {
        $rows = [];
        foreach ($this->db->select(
            "SELECT o.order_number, o.delivered_at, c.name AS customer,
                (SELECT COALESCE(SUM(l.line_total), 0) FROM order_lines l WHERE l.order_id = o.id) AS total,
                (SELECT COALESCE(SUM(rl.line_total), 0) FROM order_return_lines rl JOIN order_returns r ON r.id = rl.return_id WHERE r.order_id = o.id) AS returned,
                (SELECT COALESCE(SUM(CASE WHEN op.kind = 'refund' THEN -t.amount ELSE t.amount END), 0) FROM order_payments op JOIN financial_transactions t ON t.id = op.financial_transaction_id WHERE op.order_id = o.id AND t.voided_at IS NULL) AS paid
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.business_id = ? AND o.status = 'delivered' ORDER BY o.delivered_at LIMIT 20000",
            [$biz]
        ) as $o) {
            $net = Decimal::from($o['total'], 2)->sub(Decimal::from($o['returned'], 2));
            $bal = $net->sub(Decimal::from($o['paid'], 2));
            if ($bal->isPositive()) {
                $rows[] = ['customer' => $o['customer'] ?? 'Mostrador', 'order' => $o['order_number'], 'date' => $local((string) $o['delivered_at']), 'total' => $net, 'paid' => Decimal::from($o['paid'], 2), 'balance' => $bal];
            }
        }
        $cols = [['key' => 'customer', 'label' => 'Cliente'], ['key' => 'order', 'label' => 'Pedido'], ['key' => 'date', 'label' => 'Entrega'],
            ['key' => 'total', 'label' => 'Total neto', 'type' => 'money'], ['key' => 'paid', 'label' => 'Cobrado', 'type' => 'money'], ['key' => 'balance', 'label' => 'Saldo', 'type' => 'money']];

        return $this->finish('Cuentas por cobrar', $sub, $cols, $rows, ['total', 'paid', 'balance'], 'customer');
    }

    private function inventory(int $biz, string $sub): Table
    {
        $rows = [];
        foreach ($this->db->select(
            'SELECT p.name AS product, v.sku, w.name AS warehouse, s.quantity, s.reserved_quantity, s.avg_cost FROM stock_levels s
             JOIN product_variants v ON v.id = s.variant_id JOIN products p ON p.id = v.product_id JOIN warehouses w ON w.id = s.warehouse_id
             WHERE s.business_id = ? AND s.quantity > 0 ORDER BY p.name, w.name LIMIT 20000',
            [$biz]
        ) as $s) {
            $rows[] = ['product' => $s['product'], 'sku' => $s['sku'], 'warehouse' => $s['warehouse'], 'qty' => Decimal::from($s['quantity'], 3), 'reserved' => Decimal::from($s['reserved_quantity'], 3),
                'cost' => Decimal::from($s['avg_cost'], 4), 'value' => Decimal::from($s['quantity'], 3)->mul(Decimal::from($s['avg_cost'], 4), 2)];
        }
        $cols = [['key' => 'product', 'label' => 'Producto'], ['key' => 'sku', 'label' => 'SKU'], ['key' => 'warehouse', 'label' => 'Almacén'], ['key' => 'qty', 'label' => 'Cantidad', 'type' => 'qty'],
            ['key' => 'reserved', 'label' => 'Reservado', 'type' => 'qty'], ['key' => 'cost', 'label' => 'Costo prom.', 'type' => 'money'], ['key' => 'value', 'label' => 'Valor', 'type' => 'money']];

        return $this->finish('Valoración de inventario', $sub, $cols, $rows, ['value'], 'product');
    }

    private function expenseRows(int $biz, array $ctx, string $sub): Table
    {
        $rows = [];
        foreach ($this->db->select(
            'SELECT e.expense_date, c.name AS category, e.description, a.name AS account, t.amount FROM expenses e
             JOIN financial_transactions t ON t.id = e.financial_transaction_id JOIN expense_categories c ON c.id = e.expense_category_id JOIN financial_accounts a ON a.id = t.account_id
             WHERE e.business_id = ? AND t.voided_at IS NULL AND e.expense_date >= ? AND e.expense_date <= ? ORDER BY e.expense_date, e.id LIMIT 20000',
            [$biz, $ctx['from'], $ctx['to']]
        ) as $e) {
            $rows[] = ['date' => $e['expense_date'], 'category' => $e['category'], 'description' => $e['description'], 'account' => $e['account'], 'amount' => Decimal::from($e['amount'], 2)];
        }
        $cols = [['key' => 'date', 'label' => 'Fecha'], ['key' => 'category', 'label' => 'Categoría'], ['key' => 'description', 'label' => 'Descripción'], ['key' => 'account', 'label' => 'Cuenta'], ['key' => 'amount', 'label' => 'Importe', 'type' => 'money']];

        return $this->finish('Gastos', $sub, $cols, $rows, ['amount'], 'date');
    }

    private function pnl(int $biz, array $ctx, string $sub): Table
    {
        $p = $this->expenses->profitAndLoss($biz, $ctx['from'], $ctx['to'], $ctx['fromUtc'], $ctx['toUtc']);
        $rows = [['concept' => 'Ventas (base de pedidos entregados)', 'amount' => $p['revenue']], ['concept' => '(−) Devoluciones', 'amount' => $p['returns']], ['concept' => '= Ingresos netos', 'amount' => $p['net_revenue']],
            ['concept' => '(−) Costo de lo vendido', 'amount' => $p['cogs']], ['concept' => '(+) Costo de lo devuelto y repuesto', 'amount' => $p['returned_cost']], ['concept' => '= Ganancia bruta', 'amount' => $p['gross']]];
        foreach ($p['by_category'] as $c) {
            $rows[] = ['concept' => '(−) Gasto: ' . $c['name'], 'amount' => Decimal::from($c['total'], 2)];
        }
        $rows[] = ['concept' => '= Ganancia neta', 'amount' => $p['net']];
        $rows = array_map(static fn (array $r): array => ['concept' => $r['concept'], 'amount' => $r['amount']->toString()], $rows);

        return new Table('Estado de resultados', $sub, [['key' => 'concept', 'label' => 'Concepto'], ['key' => 'amount', 'label' => 'Importe', 'type' => 'money']], $rows);
    }

    /**
     * Convierte Decimal a texto y calcula la fila de totales.
     *
     * @param list<array{key:string,label:string,type?:string}> $cols
     * @param list<array<string,mixed>> $rows
     * @param list<string> $sumKeys
     */
    private function finish(string $title, string $sub, array $cols, array $rows, array $sumKeys, string $labelKey): Table
    {
        $keys = array_column($cols, 'key');
        $sums = [];
        foreach ($sumKeys as $k) {
            if (in_array($k, $keys, true)) {
                $sums[$k] = Decimal::zero();
            }
        }
        $out = [];
        foreach ($rows as $r) {
            foreach ($sums as $k => $_) {
                if ($r[$k] instanceof Decimal) {
                    $sums[$k] = $sums[$k]->add($r[$k]);
                }
            }
            $out[] = array_map(static fn ($v) => $v instanceof Decimal ? $v->toString() : $v, $r);
        }
        $totals = null;
        if ($out !== []) {
            $totals = [$labelKey => 'Total'];
            foreach ($sums as $k => $v) {
                $totals[$k] = $v->toString();
            }
        }

        return new Table($title, $sub, $cols, $out, $totals);
    }
}
