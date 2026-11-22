<?php

declare(strict_types=1);

namespace Bic\Modules\Insights;

use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use Bic\Core\Money\TaxCalculator;

/**
 * Análisis comercial a partir de datos reales (reglas explícitas, sin predicciones inventadas).
 * Todo en moneda base; los importes de ventas son base sin impuesto, tras devoluciones.
 */
final class InsightService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Ventas netas del rango [fromUtc, toUtc). */
    public function revenue(int $biz, string $fromUtc, string $toUtc): Decimal
    {
        $net = Decimal::from($this->db->value(
            "SELECT COALESCE(SUM(l.net_amount), 0) FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ?",
            [$biz, $fromUtc, $toUtc]
        ), 2);
        $ret = Decimal::from($this->db->value(
            "SELECT COALESCE(SUM(rl.net_amount), 0) FROM order_return_lines rl JOIN order_lines l ON l.id = rl.order_line_id JOIN orders o ON o.id = l.order_id WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ?",
            [$biz, $fromUtc, $toUtc]
        ), 2);

        return $net->sub($ret);
    }

    /**
     * Compara el período con el inmediatamente anterior de igual duración.
     *
     * @return array{current:Decimal,previous:Decimal,change_pct:?string}
     */
    public function trend(int $biz, string $fromUtc, string $toUtc, string $prevFromUtc): array
    {
        $cur = $this->revenue($biz, $fromUtc, $toUtc);
        $prev = $this->revenue($biz, $prevFromUtc, $fromUtc);
        $pct = $prev->isPositive() ? $cur->sub($prev)->mul(Decimal::from(100, 2), 4)->div($prev, 2)->toString() : null;

        return ['current' => $cur, 'previous' => $prev, 'change_pct' => $pct];
    }

    /**
     * Productos con ventas del período: ventas netas, utilidad (si hay costo) y clase ABC (A: hasta 80 % acumulado, B: hasta 95 %, C: resto).
     *
     * @return list<array<string,mixed>>
     */
    public function products(int $biz, string $fromUtc, string $toUtc): array
    {
        $rows = $this->db->select(
            "SELECT p.id, p.name, SUM(l.quantity - l.returned_quantity) AS qty,
                    SUM(l.net_amount - (SELECT COALESCE(SUM(rl.net_amount), 0) FROM order_return_lines rl WHERE rl.order_line_id = l.id)) AS net,
                    SUM((l.quantity - l.returned_quantity) * COALESCE(l.unit_cost_snapshot, 0)) AS cost
             FROM order_lines l JOIN orders o ON o.id = l.order_id JOIN product_variants v ON v.id = l.variant_id JOIN products p ON p.id = v.product_id
             WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ? GROUP BY p.id, p.name ORDER BY net DESC LIMIT 2000",
            [$biz, $fromUtc, $toUtc]
        );
        $total = Decimal::zero();
        foreach ($rows as $r) {
            $total = $total->add(Decimal::from($r['net'], 2));
        }
        $cum = Decimal::zero();
        $out = [];
        foreach ($rows as $r) {
            $net = Decimal::from($r['net'], 2);
            $cost = Decimal::from($r['cost'], 2);
            $before = $cum;
            $cum = $cum->add($net);
            // la clase la decide el acumulado ANTES de sumar el producto: el que cruza el 80 % sigue siendo A
            $beforePct = $total->isPositive() ? $before->mul(Decimal::from(100, 2), 4)->div($total, 2) : Decimal::zero();
            $class = $beforePct->compare(Decimal::from(80, 2)) < 0 ? 'A' : ($beforePct->compare(Decimal::from(95, 2)) < 0 ? 'B' : 'C');
            $out[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'qty' => $r['qty'], 'net' => $net->toString(), 'cost' => $cost->toString(), 'profit' => $net->sub($cost)->toString(),
                'margin' => $net->isPositive() ? $net->sub($cost)->mul(Decimal::from(100, 2), 4)->div($net, 2)->toString() : null,
                'share' => $total->isPositive() ? $net->mul(Decimal::from(100, 2), 4)->div($total, 2)->toString() : '0.00', 'class' => $class];
        }

        return $out;
    }

    /**
     * Productos vendidos con margen realizado por debajo del mínimo esperado (solo con costo).
     *
     * @param list<array<string,mixed>> $products salida de products()
     * @return list<array<string,mixed>>
     */
    public function lowMargin(array $products, int $minPct): array
    {
        $min = Decimal::from($minPct, 2);
        $out = array_values(array_filter($products, static fn (array $p): bool => $p['margin'] !== null && Decimal::from($p['margin'], 2)->compare($min) < 0));
        usort($out, static fn (array $a, array $b): int => Decimal::from($a['margin'], 2)->compare(Decimal::from($b['margin'], 2)));

        return array_slice($out, 0, 10);
    }

    /**
     * Productos cuyo precio de lista (sin impuesto) está por debajo de su costo promedio actual.
     *
     * @return list<array<string,mixed>>
     */
    public function belowCost(int $biz): array
    {
        $out = [];
        foreach ($this->db->select(
            "SELECT p.name, v.sku, p.sale_price, p.price_includes_tax, t.rate, AVG(s.avg_cost) AS avg_cost FROM products p JOIN product_variants v ON v.product_id = p.id
             JOIN tax_rates t ON t.id = p.tax_rate_id JOIN stock_levels s ON s.variant_id = v.id AND s.quantity > 0
             WHERE p.business_id = ? AND p.status = 'active' GROUP BY p.id, p.name, v.id, v.sku, p.sale_price, p.price_includes_tax, t.rate LIMIT 2000",
            [$biz]
        ) as $r) {
            $net = TaxCalculator::netUnitPrice(Decimal::from($r['sale_price'], 2), Decimal::from($r['rate'], 4), (int) $r['price_includes_tax'] === 1);
            $cost = Decimal::from($r['avg_cost'], 4);
            if ($net->compare($cost) < 0) {
                $out[] = ['name' => $r['name'], 'sku' => $r['sku'], 'net_price' => $net->rescale(2)->toString(), 'cost' => $cost->rescale(2)->toString()];
            }
        }

        return array_slice($out, 0, 10);
    }

    /**
     * Sugerencia de reposición: disponible ≤ mínimo. Cobertura = disponible / venta diaria media del período.
     * Sugerido = mínimo + 14 días de venta media − disponible (redondeado hacia arriba a unidades enteras).
     *
     * @return list<array<string,mixed>>
     */
    public function reorder(int $biz, string $fromUtc, string $toUtc, int $days): array
    {
        $sold = [];
        foreach ($this->db->select(
            "SELECT v.id, SUM(l.quantity - l.returned_quantity) AS qty FROM order_lines l JOIN orders o ON o.id = l.order_id JOIN product_variants v ON v.id = l.variant_id
             WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ? GROUP BY v.id",
            [$biz, $fromUtc, $toUtc]
        ) as $r) {
            $sold[(int) $r['id']] = Decimal::from($r['qty'], 3);
        }
        $out = [];
        foreach ($this->db->select(
            "SELECT v.id, p.name, v.sku, p.min_stock, (SELECT COALESCE(SUM(s.quantity - s.reserved_quantity), 0) FROM stock_levels s WHERE s.variant_id = v.id) AS available
             FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.business_id = ? AND v.is_active = 1 AND p.status = 'active' AND p.min_stock > 0 LIMIT 5000",
            [$biz]
        ) as $r) {
            $avail = Decimal::from($r['available'], 3);
            $min = Decimal::from($r['min_stock'], 3);
            if ($avail->compare($min) > 0) {
                continue;
            }
            $daily = ($sold[(int) $r['id']] ?? Decimal::zero())->div(Decimal::from($days, 3), 3);
            $cover = $daily->isPositive() ? $avail->div($daily, 1)->toString() : null;
            $target = $min->add($daily->mul(Decimal::from(14, 3), 3));
            $need = $target->sub($avail);
            $suggested = $need->isPositive() ? $need->div(Decimal::from(1, 3), 0, true)->toString() : '0';
            $out[] = ['name' => $r['name'], 'sku' => $r['sku'], 'available' => $avail->toString(), 'min' => $min->toString(), 'daily' => $daily->toString(), 'cover_days' => $cover, 'suggested' => $suggested];
        }
        usort($out, static fn (array $a, array $b): int => ($a['cover_days'] === null ? 1 : 0) <=> ($b['cover_days'] === null ? 1 : 0) ?: (float) ($a['cover_days'] ?? 0) <=> (float) ($b['cover_days'] ?? 0));

        return array_slice($out, 0, 10);
    }

    /**
     * Existencias sin ventas en los últimos $slowDays días (o nunca vendidas), con el capital inmovilizado.
     *
     * @return list<array<string,mixed>>
     */
    public function slowMovers(int $biz, string $sinceUtc, bool $withCost): array
    {
        $out = [];
        foreach ($this->db->select(
            "SELECT p.name, v.sku, SUM(s.quantity) AS qty, SUM(s.quantity * s.avg_cost) AS value,
                    (SELECT MAX(o.delivered_at) FROM order_lines l JOIN orders o ON o.id = l.order_id WHERE l.variant_id = v.id AND o.status = 'delivered') AS last_sale
             FROM stock_levels s JOIN product_variants v ON v.id = s.variant_id JOIN products p ON p.id = v.product_id
             WHERE s.business_id = ? AND s.quantity > 0 GROUP BY v.id, p.name, v.sku ORDER BY value DESC LIMIT 2000",
            [$biz]
        ) as $r) {
            if ($r['last_sale'] === null || (string) $r['last_sale'] < $sinceUtc) {
                $out[] = ['name' => $r['name'], 'sku' => $r['sku'], 'qty' => Decimal::from($r['qty'], 3)->toString(), 'last_sale' => $r['last_sale'],
                    'value' => $withCost ? Decimal::from($r['value'], 2)->toString() : null];
            }
        }

        return array_slice($out, 0, 10);
    }

    /** @return list<array<string,mixed>> mejores clientes del período por ventas netas */
    public function topCustomers(int $biz, string $fromUtc, string $toUtc): array
    {
        return $this->db->select(
            "SELECT c.id, c.name, COUNT(DISTINCT o.id) AS orders, SUM(l.net_amount - (SELECT COALESCE(SUM(rl.net_amount), 0) FROM order_return_lines rl WHERE rl.order_line_id = l.id)) AS net, MAX(o.delivered_at) AS last_order
             FROM order_lines l JOIN orders o ON o.id = l.order_id JOIN customers c ON c.id = o.customer_id
             WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ? GROUP BY c.id, c.name ORDER BY net DESC LIMIT 5",
            [$biz, $fromUtc, $toUtc]
        );
    }
}
