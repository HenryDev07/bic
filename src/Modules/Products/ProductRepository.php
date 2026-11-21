<?php

declare(strict_types=1);

namespace Bic\Modules\Products;

use Bic\Core\Database\Database;
use Bic\Core\Paginator;

final class ProductRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{0:string,1:list<mixed>} */
    private function filter(int $biz, string $q, string $status, int $categoryId): array
    {
        $where = ['p.business_id = ?'];
        $params = [$biz];
        if ($q !== '') {
            $like = Paginator::like($q);
            $where[] = "(p.name LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM product_variants x WHERE x.product_id = p.id AND (x.sku LIKE ? ESCAPE '!' OR x.barcode LIKE ? ESCAPE '!')))";
            array_push($params, $like, $like, $like);
        }
        if (in_array($status, ['active', 'inactive', 'discontinued'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($categoryId > 0) {
            $where[] = 'p.category_id = ?';
            $params[] = $categoryId;
        }

        return [implode(' AND ', $where), $params];
    }

    private const SELECT = 'SELECT p.*, c.name AS category_name, b.name AS brand_name, u.code AS unit_code, t.name AS tax_name, t.rate AS tax_rate,
            v.sku AS sku, v.barcode AS barcode, v.reference_cost AS reference_cost
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
        JOIN units u ON u.id = p.unit_id JOIN tax_rates t ON t.id = p.tax_rate_id
        LEFT JOIN product_variants v ON v.product_id = p.id AND v.is_default = 1';

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $biz, string $q, string $status, int $categoryId, int $limit, int $offset): array
    {
        [$w, $params] = $this->filter($biz, $q, $status, $categoryId);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM products p WHERE {$w}", $params);
        $rows = $this->db->select(self::SELECT . " WHERE {$w} ORDER BY p.name, p.id LIMIT ? OFFSET ?", array_merge($params, [$limit, $offset]));

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function export(int $biz, string $q, string $status, int $categoryId): array
    {
        [$w, $params] = $this->filter($biz, $q, $status, $categoryId);

        return $this->db->select(self::SELECT . " WHERE {$w} ORDER BY p.name, p.id LIMIT 10000", $params);
    }

    /** @return array<string,mixed>|null */
    public function find(int $biz, int $id): ?array
    {
        return $this->db->first(self::SELECT . ' WHERE p.id = ? AND p.business_id = ?', [$id, $biz]);
    }

    /** @return list<array<string,mixed>> */
    public function variants(int $biz, int $productId): array
    {
        return $this->db->select('SELECT * FROM product_variants WHERE product_id = ? AND business_id = ? ORDER BY is_default DESC, id', [$productId, $biz]);
    }

    /** @return array<string,mixed>|null */
    public function variant(int $biz, int $productId, int $variantId): ?array
    {
        return $this->db->first('SELECT * FROM product_variants WHERE id = ? AND product_id = ? AND business_id = ?', [$variantId, $productId, $biz]);
    }

    /** @return list<array<string,mixed>> */
    public function history(int $biz, int $productId, int $limit = 40): array
    {
        return $this->db->select(
            'SELECT h.*, u.name AS user_name, v.sku AS sku FROM product_price_history h
             LEFT JOIN users u ON u.id = h.changed_by LEFT JOIN product_variants v ON v.id = h.variant_id
             WHERE h.product_id = ? AND h.business_id = ? ORDER BY h.id DESC LIMIT ?',
            [$productId, $biz, $limit]
        );
    }

    /** Opciones activas para los selectores del formulario. @return array<string,list<array<string,mixed>>> */
    public function options(int $biz): array
    {
        $q = fn (string $sql): array => $this->db->select($sql, [$biz]);

        return [
            'categories' => $q('SELECT id, name FROM categories WHERE business_id = ? AND is_active = 1 ORDER BY name'),
            'brands'     => $q('SELECT id, name FROM brands WHERE business_id = ? AND is_active = 1 ORDER BY name'),
            'units'      => $q('SELECT id, code, name FROM units WHERE business_id = ? AND is_active = 1 ORDER BY code'),
            'taxes'      => $q('SELECT id, name, rate, is_default FROM tax_rates WHERE business_id = ? AND is_active = 1 ORDER BY name'),
        ];
    }

    public function owned(int $biz, string $table, int $id, bool $activeOnly = true): bool
    {
        if (!in_array($table, ['categories', 'brands', 'units', 'tax_rates'], true)) {
            throw new \InvalidArgumentException('Tabla no permitida.');
        }

        return (int) $this->db->value("SELECT COUNT(*) FROM {$table} WHERE id = ? AND business_id = ?" . ($activeOnly ? ' AND is_active = 1' : ''), [$id, $biz]) > 0;
    }

    public function defaultTaxId(int $biz): ?int
    {
        $id = $this->db->value('SELECT id FROM tax_rates WHERE business_id = ? AND is_default = 1 AND is_active = 1', [$biz]);

        return $id === null ? null : (int) $id;
    }

    public function skuTaken(int $biz, string $sku, ?int $exceptVariantId): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM product_variants WHERE business_id = ? AND LOWER(sku) = LOWER(?) AND id <> ?', [$biz, $sku, $exceptVariantId ?? 0]) > 0;
    }

    public function barcodeTaken(int $biz, string $barcode, ?int $exceptVariantId): bool
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM product_variants WHERE business_id = ? AND barcode = ? AND id <> ?', [$biz, $barcode, $exceptVariantId ?? 0]) > 0;
    }
}
