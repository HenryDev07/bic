<?php

declare(strict_types=1);

namespace Bic\Modules\Products;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;

final class ProductService
{
    public const STATUSES = ['active' => 'Activo', 'inactive' => 'Inactivo', 'discontinued' => 'Descontinuado'];

    public function __construct(
        private readonly Database $db,
        private readonly ProductRepository $repo,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Crea el producto y su variante predeterminada en una sola transacción.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function create(array $actor, array $in): array
    {
        $biz = (int) $actor['business_id'];
        [$errors, $p] = $this->validateProduct($biz, $in);
        [$verrors, $v] = $this->validateVariant($biz, $in, null);
        $errors += $verrors;
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }

        $id = $this->db->transaction(function () use ($biz, $actor, $p, $v): int {
            $id = $this->db->insert(
                'INSERT INTO products (business_id, category_id, brand_id, unit_id, tax_rate_id, name, description, price_includes_tax, sale_price, promo_price, min_stock, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$biz, $p['category_id'], $p['brand_id'], $p['unit_id'], $p['tax_rate_id'], $p['name'], $p['description'], $p['price_includes_tax'], $p['sale_price'], $p['promo_price'], $p['min_stock'], $p['status']]
            );
            $variantId = $this->db->insert(
                'INSERT INTO product_variants (business_id, product_id, sku, barcode, name, reference_cost, is_default, is_active) VALUES (?, ?, ?, ?, ?, ?, 1, 1)',
                [$biz, $id, $v['sku'], $v['barcode'], $v['name'], $v['reference_cost']]
            );
            $this->price($biz, $id, null, 'sale_price', null, $p['sale_price'], $actor);
            if ($p['promo_price'] !== null) {
                $this->price($biz, $id, null, 'promo_price', null, $p['promo_price'], $actor);
            }
            $this->price($biz, $id, $variantId, 'reference_cost', null, $v['reference_cost'], $actor);
            $this->log($actor, 'product.create', $id, null, $p + ['sku' => $v['sku']]);

            return $id;
        });

        return ['ok' => true, 'errors' => [], 'id' => $id, 'status' => 200];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function update(array $actor, int $id, array $in): array
    {
        $biz = (int) $actor['business_id'];
        $old = $this->repo->find($biz, $id);
        if ($old === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        [$errors, $p] = $this->validateProduct($biz, $in, $old);
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $id, 'status' => 422];
        }

        $this->db->transaction(function () use ($biz, $id, $actor, $p, $old): void {
            $this->db->execute(
                'UPDATE products SET category_id = ?, brand_id = ?, unit_id = ?, tax_rate_id = ?, name = ?, description = ?, price_includes_tax = ?, sale_price = ?, promo_price = ?, min_stock = ?, status = ?
                 WHERE id = ? AND business_id = ?',
                [$p['category_id'], $p['brand_id'], $p['unit_id'], $p['tax_rate_id'], $p['name'], $p['description'], $p['price_includes_tax'], $p['sale_price'], $p['promo_price'], $p['min_stock'], $p['status'], $id, $biz]
            );
            foreach (['sale_price', 'promo_price'] as $kind) {
                if (!$this->same($old[$kind], $p[$kind], 2)) {
                    $this->price($biz, $id, null, $kind, $old[$kind], $p[$kind], $actor);
                }
            }
            $before = array_intersect_key($old, $p);
            $this->log($actor, 'product.update', $id, $before, $p);
        });

        return ['ok' => true, 'errors' => [], 'id' => $id, 'status' => 200];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function saveVariant(array $actor, int $productId, ?int $variantId, array $in): array
    {
        $biz = (int) $actor['business_id'];
        if ($this->repo->find($biz, $productId) === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        $old = $variantId !== null ? $this->repo->variant($biz, $productId, $variantId) : null;
        if ($variantId !== null && $old === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        [$errors, $v] = $this->validateVariant($biz, $in, $variantId);
        if ($old !== null && (int) $old['is_default'] === 1 && empty($in['is_active'])) {
            $errors['is_active'] = 'La variante predeterminada no se puede desactivar.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $variantId, 'status' => 422];
        }
        $active = !empty($in['is_active']) || $variantId === null ? 1 : 0;

        $id = $this->db->transaction(function () use ($biz, $productId, $variantId, $v, $old, $active, $actor): int {
            if ($variantId === null) {
                $id = $this->db->insert(
                    'INSERT INTO product_variants (business_id, product_id, sku, barcode, name, reference_cost, is_default, is_active) VALUES (?, ?, ?, ?, ?, ?, 0, 1)',
                    [$biz, $productId, $v['sku'], $v['barcode'], $v['name'], $v['reference_cost']]
                );
                $this->price($biz, $productId, $id, 'reference_cost', null, $v['reference_cost'], $actor);
                $this->log($actor, 'variant.create', $id, null, $v);

                return $id;
            }
            $this->db->execute(
                'UPDATE product_variants SET sku = ?, barcode = ?, name = ?, reference_cost = ?, is_active = ? WHERE id = ? AND business_id = ?',
                [$v['sku'], $v['barcode'], $v['name'], $v['reference_cost'], $active, $variantId, $biz]
            );
            if (!$this->same($old['reference_cost'], $v['reference_cost'], 4)) {
                $this->price($biz, $productId, $variantId, 'reference_cost', $old['reference_cost'], $v['reference_cost'], $actor);
            }
            $this->log($actor, 'variant.update', $variantId, array_intersect_key($old, $v), $v);

            return $variantId;
        });

        return ['ok' => true, 'errors' => [], 'id' => $id, 'status' => 200];
    }

    // ---- validación ----

    /**
     * @param array<string,mixed> $in
     * @param array<string,mixed>|null $old
     * @return array{0:array<string,string>,1:array<string,mixed>}
     */
    private function validateProduct(int $biz, array $in, ?array $old = null): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($name) > 190) {
            $errors['name'] = 'El nombre no puede superar 190 caracteres.';
        }

        if (trim((string) ($in['tax_rate_id'] ?? '')) === '' && $old === null) {
            $in['tax_rate_id'] = (string) ($this->repo->defaultTaxId($biz) ?? '');
        }

        $pick = function (string $field, string $table, bool $required, string $label) use ($biz, $in, $old, &$errors): ?int {
            $raw = trim((string) ($in[$field] ?? ''));
            if ($raw === '') {
                if ($required) {
                    $errors[$field] = "Selecciona {$label}.";
                }

                return null;
            }
            $id = (int) $raw;
            $unchanged = $old !== null && (int) $old[$field] === $id; // se permite conservar un valor que luego se desactivó
            if ($id <= 0 || !$this->repo->owned($biz, $table, $id, !$unchanged)) {
                $errors[$field] = "La {$label} elegida no es válida.";

                return null;
            }

            return $id;
        };

        $category = $pick('category_id', 'categories', false, 'categoría');
        $brand = $pick('brand_id', 'brands', false, 'marca');
        $unit = $pick('unit_id', 'units', true, 'unidad');
        $tax = $pick('tax_rate_id', 'tax_rates', true, 'tasa de impuesto');

        $sale = Decimal::fromInput(($in['sale_price'] ?? '') === '' ? '0' : $in['sale_price'] ?? '', 2);
        if ($sale === null || $sale->isNegative()) {
            $errors['sale_price'] = 'El precio de venta debe ser un número mayor o igual a 0.';
        }
        $promo = null;
        if (trim((string) ($in['promo_price'] ?? '')) !== '') {
            $promo = Decimal::fromInput($in['promo_price'], 2);
            if ($promo === null || $promo->isNegative()) {
                $errors['promo_price'] = 'El precio promocional debe ser un número mayor o igual a 0.';
            } elseif ($sale !== null && $promo->compare($sale) > 0) {
                $errors['promo_price'] = 'La promoción no puede ser mayor que el precio de venta.';
            }
        }
        $min = Decimal::fromInput(($in['min_stock'] ?? '') === '' ? '0' : $in['min_stock'] ?? '', 3);
        if ($min === null || $min->isNegative()) {
            $errors['min_stock'] = 'El stock mínimo debe ser un número mayor o igual a 0.';
        }
        $status = trim((string) ($in['status'] ?? '')) ?: 'active';
        if (!isset(self::STATUSES[$status])) {
            $errors['status'] = 'Estado no válido.';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($desc) > 2000) {
            $errors['description'] = 'La descripción no puede superar 2000 caracteres.';
        }

        return [$errors, [
            'category_id' => $category, 'brand_id' => $brand, 'unit_id' => $unit, 'tax_rate_id' => $tax,
            'name' => $name, 'description' => $desc === '' ? null : $desc,
            'price_includes_tax' => !empty($in['price_includes_tax']) ? 1 : 0,
            'sale_price' => $sale?->toString(), 'promo_price' => $promo?->toString(),
            'min_stock' => $min?->toString(), 'status' => $status,
        ]];
    }

    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,string>,1:array<string,mixed>}
     */
    private function validateVariant(int $biz, array $in, ?int $variantId): array
    {
        $errors = [];
        $sku = trim((string) ($in['sku'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,60}$/', $sku)) {
            $errors['sku'] = 'El SKU es obligatorio (letras, números, punto, guion o barra; máx. 60).';
        } elseif ($this->repo->skuTaken($biz, $sku, $variantId)) {
            $errors['sku'] = 'Ese SKU ya existe.';
        }
        $barcode = trim((string) ($in['barcode'] ?? ''));
        if ($barcode !== '') {
            if (!preg_match('/^[A-Za-z0-9-]{1,60}$/', $barcode)) {
                $errors['barcode'] = 'Código de barras no válido.';
            } elseif ($this->repo->barcodeTaken($biz, $barcode, $variantId)) {
                $errors['barcode'] = 'Ese código de barras ya existe.';
            }
        }
        $vname = trim((string) ($in['variant_name'] ?? ''));
        if (mb_strlen($vname) > 120) {
            $errors['variant_name'] = 'El nombre de variante no puede superar 120 caracteres.';
        }
        $cost = Decimal::fromInput(($in['reference_cost'] ?? '') === '' ? '0' : $in['reference_cost'] ?? '', 4);
        if ($cost === null || $cost->isNegative()) {
            $errors['reference_cost'] = 'El costo debe ser un número mayor o igual a 0.';
        }

        return [$errors, [
            'sku' => $sku, 'barcode' => $barcode === '' ? null : $barcode,
            'name' => $vname === '' ? null : $vname, 'reference_cost' => $cost?->toString(),
        ]];
    }

    private function same(mixed $a, mixed $b, int $scale): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return Decimal::from($a, $scale)->equals(Decimal::from($b, $scale));
    }

    /** @param array<string,mixed> $actor */
    private function price(int $biz, int $productId, ?int $variantId, string $kind, mixed $old, mixed $new, array $actor): void
    {
        $this->db->execute(
            'INSERT INTO product_price_history (business_id, product_id, variant_id, kind, old_price, new_price, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$biz, $productId, $variantId, $kind, $old, $new, $actor['id']]
        );
    }

    /** @param array<string,mixed> $actor @param array<string,mixed>|null $old @param array<string,mixed>|null $new */
    private function log(array $actor, string $action, int $id, ?array $old, ?array $new): void
    {
        $this->audit->log((int) $actor['business_id'], (int) $actor['id'], $action, str_starts_with($action, 'variant') ? 'product_variants' : 'products', $id, $old, $new, $actor['ip'] ?? null, $actor['ua'] ?? null);
    }
}
