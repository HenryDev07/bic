<?php

declare(strict_types=1);

namespace Bic\Modules\Research;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use Bic\Core\Paginator;

/** Investigación de productos: ideas con costo y precio objetivo, y precios observados en el mercado (historial inmutable). */
final class ResearchService
{
    public const STATUSES = ['idea' => 'Idea', 'evaluating' => 'Evaluando', 'approved' => 'Aprobado', 'discarded' => 'Descartado'];

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $biz, int $id): ?array
    {
        return $this->db->first('SELECT r.*, p.name AS product_name FROM research_items r LEFT JOIN products p ON p.id = r.product_id WHERE r.id = ? AND r.business_id = ?', [$id, $biz]);
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function paginate(int $biz, string $q, string $status, int $limit, int $offset): array
    {
        $where = ['business_id = ?'];
        $params = [$biz];
        if ($q !== '') {
            $where[] = "name LIKE ? ESCAPE '!'";
            $params[] = Paginator::like($q);
        }
        if (isset(self::STATUSES[$status])) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM research_items WHERE {$w}", $params);
        $rows = $this->db->select(
            "SELECT r.*, (SELECT MIN(p.observed_price) FROM research_prices p WHERE p.research_item_id = r.id) AS min_price,
                    (SELECT COUNT(*) FROM research_prices p WHERE p.research_item_id = r.id) AS observations
             FROM research_items r WHERE {$w} ORDER BY r.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return list<array<string,mixed>> */
    public function prices(int $biz, int $id): array
    {
        return $this->db->select('SELECT * FROM research_prices WHERE research_item_id = ? AND business_id = ? ORDER BY observed_on DESC, id DESC', [$id, $biz]);
    }

    /** @return list<array<string,mixed>> */
    public function productOptions(int $biz): array
    {
        return $this->db->select("SELECT id, name FROM products WHERE business_id = ? AND status <> 'discontinued' ORDER BY name LIMIT 1000", [$biz]);
    }

    /**
     * Mínimo / promedio / máximo de lo observado y margen del precio objetivo sobre el costo estimado.
     *
     * @param array<string,mixed> $item
     * @param list<array<string,mixed>> $prices
     * @return array<string,?string>
     */
    public function analysis(array $item, array $prices): array
    {
        $min = $max = $sum = null;
        foreach ($prices as $p) {
            $v = Decimal::from($p['observed_price'], 2);
            $min = $min === null || $v->compare($min) < 0 ? $v : $min;
            $max = $max === null || $v->compare($max) > 0 ? $v : $max;
            $sum = $sum === null ? $v : $sum->add($v);
        }
        $avg = $sum === null ? null : $sum->div(Decimal::from(count($prices), 2), 2);
        $margin = null;
        $markup = null;
        if ($item['estimated_cost'] !== null && $item['target_price'] !== null) {
            $cost = Decimal::from($item['estimated_cost'], 2);
            $price = Decimal::from($item['target_price'], 2);
            if ($price->isPositive()) {
                $margin = $price->sub($cost)->mul(Decimal::from(100, 2), 4)->div($price, 2)->toString();
            }
            if ($cost->isPositive()) {
                $markup = $price->sub($cost)->mul(Decimal::from(100, 2), 4)->div($cost, 2)->toString();
            }
        }

        return ['min' => $min?->toString(), 'avg' => $avg?->toString(), 'max' => $max?->toString(), 'margin' => $margin, 'markup' => $markup, 'count' => (string) count($prices)];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in name, status, estimated_cost, target_price, product_id, notes
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function save(array $actor, ?int $id, array $in): array
    {
        $biz = (int) $actor['business_id'];
        $old = $id !== null ? $this->find($biz, $id) : null;
        if ($id !== null && $old === null) {
            return ['ok' => false, 'errors' => [], 'id' => null, 'status' => 404];
        }
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            $errors['name'] = 'El nombre es obligatorio (máx. 190 caracteres).';
        }
        $status = (string) ($in['status'] ?? '') ?: 'idea';
        if (!isset(self::STATUSES[$status])) {
            $errors['status'] = 'Estado no válido.';
        }
        $money = function (string $k) use ($in, &$errors): ?string {
            $raw = trim((string) ($in[$k] ?? ''));
            if ($raw === '') {
                return null;
            }
            $d = Decimal::fromInput($raw, 2);
            if ($d === null || $d->isNegative()) {
                $errors[$k] = 'Debe ser un número mayor o igual a 0.';

                return null;
            }

            return $d->toString();
        };
        $cost = $money('estimated_cost');
        $price = $money('target_price');
        $productId = (int) ($in['product_id'] ?? 0);
        if ($productId > 0 && $this->db->first('SELECT id FROM products WHERE id = ? AND business_id = ?', [$productId, $biz]) === null) {
            $errors['product_id'] = 'Producto no válido.';
        }
        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > 4000) {
            $errors['notes'] = 'Máximo 4000 caracteres.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => $id, 'status' => 422];
        }
        $v = ['name' => $name, 'status' => $status, 'estimated_cost' => $cost, 'target_price' => $price, 'product_id' => $productId > 0 ? $productId : null, 'notes' => $notes === '' ? null : $notes];

        $newId = $this->db->transaction(function () use ($biz, $id, $v, $old, $actor): int {
            if ($id === null) {
                $cols = array_keys($v);
                $newId = $this->db->insert('INSERT INTO research_items (business_id, created_by, ' . implode(', ', $cols) . ') VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_merge([$biz, $actor['id']], array_values($v)));
            } else {
                $sets = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($v)));
                $this->db->execute("UPDATE research_items SET {$sets} WHERE id = ? AND business_id = ?", array_merge(array_values($v), [$id, $biz]));
                $newId = $id;
            }
            $this->audit->log($biz, (int) $actor['id'], $id === null ? 'research.create' : 'research.update', 'research_items', $newId, $old === null ? null : array_intersect_key($old, $v), $v, $actor['ip'] ?? null, $actor['ua'] ?? null);

            return $newId;
        });

        return ['ok' => true, 'errors' => [], 'id' => $newId, 'status' => 200];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in source, observed_price, observed_on, url, notes
     * @return array{ok:bool,errors:array<string,string>,status:int}
     */
    public function addPrice(array $actor, int $id, array $in, string $today): array
    {
        $biz = (int) $actor['business_id'];
        if ($this->find($biz, $id) === null) {
            return ['ok' => false, 'errors' => [], 'status' => 404];
        }
        $errors = [];
        $source = trim((string) ($in['source'] ?? ''));
        if ($source === '' || mb_strlen($source) > 150) {
            $errors['source'] = 'Indica la fuente (tienda, competidor…; máx. 150).';
        }
        $price = Decimal::fromInput($in['observed_price'] ?? '', 2);
        if ($price === null || !$price->isPositive()) {
            $errors['observed_price'] = 'El precio debe ser mayor que 0.';
        }
        $on = (string) ($in['observed_on'] ?? '') ?: $today;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $on);
        if ($d === false || $d->format('Y-m-d') !== $on || $on > $today) {
            $errors['observed_on'] = 'Fecha no válida (no puede ser futura).';
        }
        $url = trim((string) ($in['url'] ?? ''));
        if ($url !== '' && (mb_strlen($url) > 500 || !preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL))) {
            $errors['url'] = 'Enlace no válido (debe empezar por http:// o https://).';
        }
        $notes = trim((string) ($in['notes'] ?? ''));
        if (mb_strlen($notes) > 255) {
            $errors['notes'] = 'Máximo 255 caracteres.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'status' => 422];
        }
        $pid = $this->db->insert(
            'INSERT INTO research_prices (business_id, research_item_id, source, observed_price, observed_on, url, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$biz, $id, $source, $price->toString(), $on, $url === '' ? null : $url, $notes === '' ? null : $notes, $actor['id']]
        );
        $this->audit->log($biz, (int) $actor['id'], 'research.price_add', 'research_prices', $pid, null, ['item' => $id, 'source' => $source, 'price' => $price->toString()], $actor['ip'] ?? null, $actor['ua'] ?? null);

        return ['ok' => true, 'errors' => [], 'status' => 200];
    }
}
