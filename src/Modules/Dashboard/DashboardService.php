<?php

declare(strict_types=1);

namespace Bic\Modules\Dashboard;

use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;

/** Indicadores del panel: todo se calcula de datos reales; cada bloque se pide solo si el usuario tiene permiso. */
final class DashboardService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Ventas netas (base sin impuesto) por día local, rellenando días sin ventas con 0.
     *
     * @param callable(string):string $toLocalYmd convierte un datetime UTC a fecha local Y-m-d
     * @param list<string> $days fechas locales Y-m-d consecutivas
     * @return array{days:list<string>,values:list<Decimal>,total:Decimal,orders:int}
     */
    public function salesByDay(int $biz, string $fromUtc, string $toUtc, array $days, callable $toLocalYmd): array
    {
        $by = array_fill_keys($days, Decimal::zero());
        $rows = $this->db->select(
            "SELECT o.id, o.delivered_at, SUM(l.net_amount) AS net FROM orders o JOIN order_lines l ON l.order_id = o.id
             WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ? GROUP BY o.id, o.delivered_at",
            [$biz, $fromUtc, $toUtc]
        );
        $total = Decimal::zero();
        foreach ($rows as $r) {
            $d = $toLocalYmd((string) $r['delivered_at']);
            if (isset($by[$d])) {
                $by[$d] = $by[$d]->add(Decimal::from($r['net'], 2));
                $total = $total->add(Decimal::from($r['net'], 2));
            }
        }

        return ['days' => $days, 'values' => array_values($by), 'total' => $total, 'orders' => count($rows)];
    }

    /** @return list<array{name:string,sku:string,qty:string,net:string}> */
    public function topProducts(int $biz, string $fromUtc, string $toUtc, int $limit = 5): array
    {
        return $this->db->select(
            "SELECT p.name, MIN(v.sku) AS sku, SUM(l.quantity - l.returned_quantity) AS qty, SUM(l.net_amount) AS net
             FROM order_lines l JOIN orders o ON o.id = l.order_id JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
             WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ? GROUP BY p.id, p.name ORDER BY net DESC LIMIT " . (int) $limit,
            [$biz, $fromUtc, $toUtc]
        );
    }

    /** @return array<string,int> pedidos abiertos por estado */
    public function openOrders(int $biz): array
    {
        $out = ['draft' => 0, 'pending' => 0, 'confirmed' => 0, 'prepared' => 0];
        foreach ($this->db->select("SELECT status, COUNT(*) AS n FROM orders WHERE business_id = ? AND status IN ('draft','pending','confirmed','prepared') GROUP BY status", [$biz]) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /** Por cobrar: suma de saldos positivos de pedidos entregados (total − devuelto − cobrado neto). */
    public function receivables(int $biz): Decimal
    {
        $rows = $this->db->select(
            "SELECT o.id,
                (SELECT COALESCE(SUM(l.line_total), 0) FROM order_lines l WHERE l.order_id = o.id) AS total,
                (SELECT COALESCE(SUM(rl.line_total), 0) FROM order_return_lines rl JOIN order_returns r ON r.id = rl.return_id WHERE r.order_id = o.id) AS returned,
                (SELECT COALESCE(SUM(CASE WHEN op.kind = 'refund' THEN -t.amount ELSE t.amount END), 0) FROM order_payments op JOIN financial_transactions t ON t.id = op.financial_transaction_id
                  WHERE op.order_id = o.id AND t.voided_at IS NULL) AS paid
             FROM orders o WHERE o.business_id = ? AND o.status = 'delivered' ORDER BY o.id DESC LIMIT 5000",
            [$biz]
        );
        $sum = Decimal::zero();
        foreach ($rows as $r) {
            $bal = Decimal::from($r['total'], 2)->sub(Decimal::from($r['returned'], 2))->sub(Decimal::from($r['paid'], 2));
            if ($bal->isPositive()) {
                $sum = $sum->add($bal);
            }
        }

        return $sum;
    }

    /** Anticipos de pedidos aún no entregados (dinero de clientes, es un pasivo). */
    public function deposits(int $biz): Decimal
    {
        return Decimal::from($this->db->value(
            "SELECT COALESCE(SUM(CASE WHEN op.kind = 'refund' THEN -t.amount ELSE t.amount END), 0)
             FROM order_payments op JOIN financial_transactions t ON t.id = op.financial_transaction_id JOIN orders o ON o.id = op.order_id
             WHERE o.business_id = ? AND o.status IN ('pending','confirmed','prepared') AND t.voided_at IS NULL",
            [$biz]
        ), 2);
    }

    /** Por pagar a proveedores: pendiente de órdenes enviadas no canceladas (moneda base). */
    public function payables(int $biz, \Bic\Modules\Purchases\PurchaseService $po): Decimal
    {
        $sum = Decimal::zero();
        foreach ($this->db->select("SELECT * FROM purchase_orders WHERE business_id = ? AND status IN ('ordered','partial','received') ORDER BY id DESC LIMIT 2000", [$biz]) as $o) {
            $due = $po->totals($o, $biz)['due'];
            if ($due->isPositive()) {
                $sum = $sum->add($due);
            }
        }

        return $sum;
    }

    /** Variantes con disponible total ≤ stock mínimo (solo productos con mínimo definido). @return list<array<string,mixed>> */
    public function lowStock(int $biz, int $limit = 5): array
    {
        $avail = '(SELECT COALESCE(SUM(t.quantity - t.reserved_quantity), 0) FROM stock_levels t WHERE t.variant_id = v.id)';

        return $this->db->select(
            "SELECT p.name, v.sku, p.min_stock, {$avail} AS available FROM product_variants v JOIN products p ON p.id = v.product_id
             WHERE v.business_id = ? AND v.is_active = 1 AND p.status = 'active' AND p.min_stock > 0 AND {$avail} <= p.min_stock ORDER BY ({$avail} - p.min_stock), p.name LIMIT " . (int) $limit,
            [$biz]
        );
    }
}
