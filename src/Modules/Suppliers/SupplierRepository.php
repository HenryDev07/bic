<?php

declare(strict_types=1);

namespace Bic\Modules\Suppliers;

use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use Bic\Core\Paginator;

final class SupplierRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $biz, string $q, int $limit, int $offset): array
    {
        $where = 'business_id = ?';
        $params = [$biz];
        if ($q !== '') {
            $where .= " AND (name LIKE ? ESCAPE '!' OR contact_name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!')";
            $like = Paginator::like($q);
            array_push($params, $like, $like, $like);
        }
        $total = (int) $this->db->value("SELECT COUNT(*) FROM suppliers WHERE {$where}", $params);
        $rows = $this->db->select("SELECT * FROM suppliers WHERE {$where} ORDER BY name, id LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function find(int $biz, int $id): ?array
    {
        return $this->db->first('SELECT * FROM suppliers WHERE id = ? AND business_id = ?', [$id, $biz]);
    }

    public function nameTaken(int $biz, string $name, ?int $exceptId): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM suppliers WHERE business_id = ? AND LOWER(name) = LOWER(?) AND id <> ?', [$biz, $name, $exceptId ?? 0]) > 0;
    }

    /** Historial de cotizaciones de un proveedor (más recientes primero). @return list<array<string,mixed>> */
    public function quotes(int $biz, int $supplierId, int $limit = 50): array
    {
        return $this->db->select(
            'SELECT sp.*, p.id AS product_id, p.name AS product_name, ps.supplier_sku
             FROM supplier_prices sp JOIN product_suppliers ps ON ps.id = sp.product_supplier_id JOIN products p ON p.id = ps.product_id
             WHERE ps.supplier_id = ? AND sp.business_id = ? ORDER BY sp.quoted_on DESC, sp.id DESC LIMIT ?',
            [$supplierId, $biz, $limit]
        );
    }

    /** @return list<array<string,mixed>> */
    public function productOptions(int $biz): array
    {
        return $this->db->select("SELECT id, name FROM products WHERE business_id = ? AND status <> 'discontinued' ORDER BY name LIMIT 1000", [$biz]);
    }

    /**
     * Última cotización de cada proveedor activo para un producto, con su costo total unitario en moneda base
     * = (precio + flete + otros) × tipo de cambio. Ordenada de menor a mayor; la mejor vigente se marca.
     *
     * @return list<array<string,mixed>>
     */
    public function compare(int $biz, int $productId, string $today): array
    {
        $rows = $this->db->select(
            'SELECT ps.supplier_id, s.name AS supplier_name, sp.currency, sp.exchange_rate, sp.unit_price, sp.freight_cost, sp.other_cost,
                    sp.min_quantity, sp.quoted_on, sp.valid_until
             FROM product_suppliers ps
             JOIN suppliers s ON s.id = ps.supplier_id AND s.is_active = 1
             JOIN supplier_prices sp ON sp.product_supplier_id = ps.id AND sp.id = (
                 SELECT x.id FROM supplier_prices x WHERE x.product_supplier_id = ps.id ORDER BY x.quoted_on DESC, x.id DESC LIMIT 1)
             WHERE ps.product_id = ? AND ps.business_id = ?',
            [$productId, $biz]
        );
        foreach ($rows as &$r) {
            $extras = Decimal::from($r['freight_cost'], 4)->add(Decimal::from($r['other_cost'], 4));
            $r['extras'] = $extras->toString();
            $r['landed_base'] = Decimal::from($r['unit_price'], 4)->add($extras)->mul(Decimal::from($r['exchange_rate'], 8), 4)->toString();
            $r['expired'] = $r['valid_until'] !== null && (string) $r['valid_until'] < $today;
        }
        unset($r);
        usort($rows, static fn (array $a, array $b): int => Decimal::from($a['landed_base'], 4)->compare(Decimal::from($b['landed_base'], 4)));
        foreach ($rows as &$r) {
            if (!$r['expired']) {
                $r['best'] = true;
                break;
            }
        }
        unset($r);

        return $rows;
    }
}
