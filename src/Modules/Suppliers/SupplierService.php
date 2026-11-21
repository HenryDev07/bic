<?php

declare(strict_types=1);

namespace Bic\Modules\Suppliers;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use DateTimeImmutable;

final class SupplierService
{
    public function __construct(private readonly Database $db, private readonly SupplierRepository $repo, private readonly AuditLogger $audit)
    {
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function save(array $actor, ?int $id, array $in): array
    {
        $biz = (int) $actor['business_id'];
        $old = $id !== null ? $this->repo->find($biz, $id) : null;
        if ($id !== null && $old === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        $errors = [];
        $t = static fn (string $k): string => trim((string) ($in[$k] ?? ''));
        $name = $t('name');
        if ($name === '' || mb_strlen($name) > 190) {
            $errors['name'] = 'El nombre es obligatorio (máx. 190 caracteres).';
        } elseif ($this->repo->nameTaken($biz, $name, $id)) {
            $errors['name'] = 'Ya existe un proveedor con ese nombre.';
        }
        $email = $t('email');
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
            $errors['email'] = 'Correo no válido.';
        }
        $currency = strtoupper($t('currency') ?: 'USD');
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = 'Moneda: código de 3 letras (USD, EUR…).';
        }
        $lead = $t('lead_time_days');
        if ($lead !== '' && !preg_match('/^\d{1,4}$/', $lead)) {
            $errors['lead_time_days'] = 'El plazo de entrega son días (número entero).';
        }
        foreach (['tax_id' => 50, 'contact_name' => 150, 'phone' => 50, 'country' => 80, 'payment_terms' => 120, 'notes' => 2000] as $k => $max) {
            if (mb_strlen($t($k)) > $max) {
                $errors[$k] = "Máximo {$max} caracteres.";
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $id, 'status' => 422];
        }
        $n = static fn (string $v): ?string => $v === '' ? null : $v;
        $v = [
            'name' => $name, 'tax_id' => $n($t('tax_id')), 'contact_name' => $n($t('contact_name')), 'email' => $n($email),
            'phone' => $n($t('phone')), 'country' => $n($t('country')), 'currency' => $currency, 'payment_terms' => $n($t('payment_terms')),
            'lead_time_days' => $lead === '' ? null : (int) $lead, 'notes' => $n($t('notes')), 'is_active' => $id === null || !empty($in['is_active']) ? 1 : 0,
        ];

        $newId = $this->db->transaction(function () use ($biz, $id, $v, $old, $actor): int {
            if ($id === null) {
                $cols = array_keys($v);
                $newId = $this->db->insert('INSERT INTO suppliers (business_id, ' . implode(', ', $cols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_merge([$biz], array_values($v)));
            } else {
                $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($v)));
                $this->db->execute("UPDATE suppliers SET {$sets} WHERE id = ? AND business_id = ?", array_merge(array_values($v), [$id, $biz]));
                $newId = $id;
            }
            $this->audit->log($biz, (int) $actor['id'], $id === null ? 'supplier.create' : 'supplier.update', 'suppliers', $newId,
                $old === null ? null : array_intersect_key($old, $v), $v, $actor['ip'] ?? null, $actor['ua'] ?? null);

            return $newId;
        });

        return ['ok' => true, 'errors' => [], 'id' => $newId, 'status' => 200];
    }

    /**
     * Registra una cotización (historial inmutable) y enlaza producto–proveedor si no existía.
     *
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function addQuote(array $actor, int $supplierId, array $in, string $today): array
    {
        $biz = (int) $actor['business_id'];
        $supplier = $this->repo->find($biz, $supplierId);
        if ($supplier === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        $errors = [];
        $productId = (int) ($in['product_id'] ?? 0);
        if ($productId <= 0 || (int) $this->db->value('SELECT COUNT(*) FROM products WHERE id = ? AND business_id = ?', [$productId, $biz]) === 0) {
            $errors['product_id'] = 'Selecciona un producto.';
        }
        $currency = strtoupper(trim((string) ($in['currency'] ?? '')) ?: (string) $supplier['currency']);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = 'Moneda no válida.';
        }
        $base = (string) $this->db->value('SELECT base_currency FROM businesses WHERE id = ?', [$biz]);
        $rate = $currency === $base ? Decimal::from(1, 8) : Decimal::fromInput($in['exchange_rate'] ?? '', 8);
        if ($rate === null || !$rate->isPositive()) {
            $errors['exchange_rate'] = "Indica el tipo de cambio a {$base} (mayor que 0).";
        }
        $money = static function (string $k, bool $required) use ($in, &$errors): ?Decimal {
            $raw = trim((string) ($in[$k] ?? ''));
            if ($raw === '') {
                if ($required) {
                    $errors[$k] = 'Obligatorio.';
                }

                return Decimal::zero(4);
            }
            $d = Decimal::fromInput($raw, 4);
            if ($d === null || $d->isNegative()) {
                $errors[$k] = 'Debe ser un número mayor o igual a 0.';
            }

            return $d;
        };
        $price = $money('unit_price', true);
        $freight = $money('freight_cost', false);
        $other = $money('other_cost', false);
        $minRaw = trim((string) ($in['min_quantity'] ?? ''));
        $minQty = $minRaw === '' ? Decimal::from(1, 3) : Decimal::fromInput($minRaw, 3);
        if ($minQty === null || !$minQty->isPositive()) {
            $errors['min_quantity'] = 'La cantidad mínima debe ser mayor que 0.';
        }
        $quoted = trim((string) ($in['quoted_on'] ?? '')) ?: $today;
        $valid = trim((string) ($in['valid_until'] ?? ''));
        if (!$this->isDate($quoted) || $quoted > $today) {
            $errors['quoted_on'] = 'Fecha de cotización no válida (no puede ser futura).';
        }
        if ($valid !== '' && (!$this->isDate($valid) || $valid < $quoted)) {
            $errors['valid_until'] = 'La vigencia debe ser una fecha igual o posterior a la cotización.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }

        $id = $this->db->transaction(function () use ($biz, $supplierId, $productId, $currency, $rate, $price, $freight, $other, $minQty, $quoted, $valid, $in, $actor): int {
            $link = $this->db->value('SELECT id FROM product_suppliers WHERE product_id = ? AND supplier_id = ? AND business_id = ?', [$productId, $supplierId, $biz]);
            if ($link === null) {
                $link = $this->db->insert('INSERT INTO product_suppliers (business_id, product_id, supplier_id, supplier_sku) VALUES (?, ?, ?, ?)',
                    [$biz, $productId, $supplierId, trim((string) ($in['supplier_sku'] ?? '')) ?: null]);
            }
            $id = $this->db->insert(
                'INSERT INTO supplier_prices (business_id, product_supplier_id, currency, exchange_rate, unit_price, freight_cost, other_cost, min_quantity, quoted_on, valid_until, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$biz, (int) $link, $currency, $rate->toString(), $price->toString(), $freight->toString(), $other->toString(), $minQty->toString(), $quoted,
                    $valid === '' ? null : $valid, mb_substr(trim((string) ($in['notes'] ?? '')), 0, 255) ?: null, $actor['id']]
            );
            $this->audit->log($biz, (int) $actor['id'], 'supplier.quote', 'supplier_prices', $id, null,
                ['supplier_id' => $supplierId, 'product_id' => $productId, 'unit_price' => $price->toString(), 'currency' => $currency], $actor['ip'] ?? null, $actor['ua'] ?? null);

            return $id;
        });

        return ['ok' => true, 'errors' => [], 'id' => $id, 'status' => 200];
    }

    private function isDate(string $v): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
