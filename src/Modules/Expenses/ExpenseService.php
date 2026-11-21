<?php

declare(strict_types=1);

namespace Bic\Modules\Expenses;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Finance\InsufficientFunds;

/** Gastos operativos: cada gasto es una salida del libro de caja (1:1) y reduce la utilidad neta. */
final class ExpenseService
{
    public const DEFAULT_CATEGORIES = ['Alquiler', 'Servicios', 'Sueldos', 'Marketing', 'Transporte', 'Impuestos y tasas', 'Otros'];

    public function __construct(private readonly Database $db, private readonly AuditLogger $audit, private readonly FinanceService $finance)
    {
    }

    /** @return list<array<string,mixed>> */
    public function categories(int $biz, bool $onlyActive = true): array
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM expense_categories WHERE business_id = ?', [$biz]) === 0) {
            foreach (self::DEFAULT_CATEGORIES as $name) {
                $this->db->insert('INSERT INTO expense_categories (business_id, name) VALUES (?, ?)', [$biz, $name]);
            }
        }

        return $this->db->select('SELECT * FROM expense_categories WHERE business_id = ?' . ($onlyActive ? ' AND is_active = 1' : '') . ' ORDER BY name', [$biz]);
    }

    /** @param array<string,mixed> $actor @return array{ok:bool,error:?string} */
    public function addCategory(array $actor, string $name): array
    {
        $biz = (int) $actor['business_id'];
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'error' => 'El nombre es obligatorio (máx. 100 caracteres).'];
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM expense_categories WHERE business_id = ? AND LOWER(name) = LOWER(?)', [$biz, $name]) > 0) {
            return ['ok' => false, 'error' => 'Ya existe esa categoría.'];
        }
        $id = $this->db->insert('INSERT INTO expense_categories (business_id, name) VALUES (?, ?)', [$biz, $name]);
        $this->audit->log($biz, (int) $actor['id'], 'expense_category.create', 'expense_categories', $id, null, ['name' => $name], $actor['ip'] ?? null, $actor['ua'] ?? null);

        return ['ok' => true, 'error' => null];
    }

    /**
     * @param array<string,mixed> $actor
     * @param array<string,mixed> $in category_id, account_id, amount, expense_date, description, order_id, product_id, supplier_id
     * @return array{ok:bool,errors:array<string,string>,id:?int,status:int}
     */
    public function create(array $actor, array $in, string $today, string $occurredAtUtc): array
    {
        $biz = (int) $actor['business_id'];
        $errors = [];
        $cat = $this->db->first('SELECT id FROM expense_categories WHERE id = ? AND business_id = ? AND is_active = 1', [(int) ($in['category_id'] ?? 0), $biz]);
        if ($cat === null) {
            $errors['category_id'] = 'Selecciona una categoría.';
        }
        $account = $this->finance->account($biz, (int) ($in['account_id'] ?? 0));
        if ($account === null || (int) $account['is_active'] !== 1) {
            $errors['account_id'] = 'Selecciona una cuenta activa.';
        }
        $amount = Decimal::fromInput($in['amount'] ?? '', 2);
        if ($amount === null || !$amount->isPositive()) {
            $errors['amount'] = 'El importe debe ser mayor que 0.';
        }
        $date = (string) ($in['expense_date'] ?? '') ?: $today;
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            $errors['expense_date'] = 'Fecha no válida.';
        } elseif ($date > $today) {
            $errors['expense_date'] = 'La fecha no puede ser futura.';
        }
        $desc = trim((string) ($in['description'] ?? ''));
        if ($desc === '' || mb_strlen($desc) > 255) {
            $errors['description'] = 'La descripción es obligatoria (máx. 255 caracteres).';
        }
        $links = [];
        foreach (['order_id' => 'orders', 'product_id' => 'products', 'supplier_id' => 'suppliers'] as $k => $table) {
            $v = (int) ($in[$k] ?? 0);
            if ($v > 0) {
                if ($this->db->first("SELECT id FROM {$table} WHERE id = ? AND business_id = ?", [$v, $biz]) === null) {
                    $errors[$k] = 'Referencia no válida.';
                }
                $links[$k] = $v;
            } else {
                $links[$k] = null;
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'id' => null, 'status' => 422];
        }

        try {
            $id = $this->db->transaction(function () use ($actor, $biz, $cat, $account, $amount, $date, $desc, $links, $occurredAtUtc): int {
                $this->db->value('SELECT id FROM financial_accounts WHERE id = ?' . $this->db->forUpdate(), [$account['id']]);
                $tx = $this->finance->post($biz, (int) $account['id'], 'out', $amount, $occurredAtUtc, 'expense', $desc, null, $actor);
                $id = $this->db->insert(
                    'INSERT INTO expenses (business_id, expense_category_id, financial_transaction_id, expense_date, description, order_id, product_id, supplier_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$biz, $cat['id'], $tx, $date, $desc, $links['order_id'], $links['product_id'], $links['supplier_id'], $actor['id']]
                );
                $this->audit->log($biz, (int) $actor['id'], 'expense.create', 'expenses', $id, null, ['amount' => $amount->toString(), 'date' => $date, 'category_id' => $cat['id']], $actor['ip'] ?? null, $actor['ua'] ?? null);

                return $id;
            });
        } catch (InsufficientFunds $e) {
            return ['ok' => false, 'errors' => ['amount' => $e->getMessage()], 'id' => null, 'status' => 422];
        }

        return ['ok' => true, 'errors' => [], 'id' => $id, 'status' => 200];
    }

    /** @param array<string,mixed> $actor @return array{ok:bool,error:?string,status:int} */
    public function void(array $actor, int $id, string $reason, string $nowUtc): array
    {
        $biz = (int) $actor['business_id'];
        $e = $this->db->first('SELECT * FROM expenses WHERE id = ? AND business_id = ?', [$id, $biz]);
        if ($e === null) {
            return ['ok' => false, 'error' => null, 'status' => 404];
        }
        $r = $this->finance->void($actor, (int) $e['financial_transaction_id'], $reason, $nowUtc, true);
        if ($r['ok']) {
            $this->audit->log($biz, (int) $actor['id'], 'expense.void', 'expenses', $id, null, ['reason' => trim($reason)], $actor['ip'] ?? null, $actor['ua'] ?? null);
        }

        return $r;
    }

    /**
     * @param array{from?:string,to?:string,category_id?:int} $f fechas Y-m-d (inclusivas)
     * @return array{rows:list<array<string,mixed>>,total:int,sum:string}
     */
    public function paginate(int $biz, array $f, int $limit, int $offset): array
    {
        $where = ['e.business_id = ?'];
        $params = [$biz];
        if (!empty($f['from'])) {
            $where[] = 'e.expense_date >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'e.expense_date <= ?';
            $params[] = $f['to'];
        }
        if (!empty($f['category_id'])) {
            $where[] = 'e.expense_category_id = ?';
            $params[] = (int) $f['category_id'];
        }
        $w = implode(' AND ', $where);
        $from = 'FROM expenses e JOIN financial_transactions t ON t.id = e.financial_transaction_id';
        $total = (int) $this->db->value("SELECT COUNT(*) {$from} WHERE {$w}", $params);
        $sum = Decimal::from($this->db->value("SELECT COALESCE(SUM(t.amount), 0) {$from} WHERE {$w} AND t.voided_at IS NULL", $params), 2);
        $rows = $this->db->select(
            "SELECT e.*, c.name AS category_name, t.amount, t.voided_at, t.void_reason, a.name AS account_name
             {$from} JOIN expense_categories c ON c.id = e.expense_category_id JOIN financial_accounts a ON a.id = t.account_id
             WHERE {$w} ORDER BY e.expense_date DESC, e.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['rows' => $rows, 'total' => $total, 'sum' => $sum->toString()];
    }

    /** @return list<array<string,mixed>> */
    public function lookups(int $biz, string $table): array
    {
        return match ($table) {
            'suppliers' => $this->db->select('SELECT id, name FROM suppliers WHERE business_id = ? AND is_active = 1 ORDER BY name LIMIT 1000', [$biz]),
            'products' => $this->db->select("SELECT id, name FROM products WHERE business_id = ? AND status <> 'discontinued' ORDER BY name LIMIT 1000", [$biz]),
            default => [],
        };
    }

    /**
     * Estado de resultados del período (todo en moneda base, sin impuestos):
     * ingresos = base de los pedidos entregados en el período − base de las devoluciones del período;
     * costo de ventas = costo congelado de lo entregado − costo de lo devuelto y repuesto (lo no repuesto queda como pérdida);
     * ganancia bruta = ingresos − costo; ganancia neta = bruta − gastos vigentes del período.
     *
     * @return array<string,mixed>
     */
    public function profitAndLoss(int $biz, string $fromDate, string $toDate, string $fromUtc, string $toUtc): array
    {
        $revenue = $cogs = $returns = $returnedCost = Decimal::zero();
        foreach ($this->db->select(
            "SELECT l.net_amount, l.quantity, l.unit_cost_snapshot FROM order_lines l JOIN orders o ON o.id = l.order_id
             WHERE o.business_id = ? AND o.status = 'delivered' AND o.delivered_at >= ? AND o.delivered_at < ?",
            [$biz, $fromUtc, $toUtc]
        ) as $l) {
            $revenue = $revenue->add(Decimal::from($l['net_amount'], 2));
            $cogs = $cogs->add(Decimal::from($l['quantity'], 3)->mul(Decimal::from($l['unit_cost_snapshot'] ?? 0, 4), 2));
        }
        foreach ($this->db->select(
            'SELECT rl.net_amount, rl.quantity, rl.unit_cost, rl.stock_movement_id FROM order_return_lines rl JOIN order_returns r ON r.id = rl.return_id
             WHERE r.business_id = ? AND r.returned_on >= ? AND r.returned_on <= ?',
            [$biz, $fromDate, $toDate]
        ) as $rl) {
            $returns = $returns->add(Decimal::from($rl['net_amount'], 2));
            if ($rl['stock_movement_id'] !== null) {
                $returnedCost = $returnedCost->add(Decimal::from($rl['quantity'], 3)->mul(Decimal::from($rl['unit_cost'] ?? 0, 4), 2));
            }
        }
        $byCategory = $this->db->select(
            'SELECT c.name, SUM(t.amount) AS total FROM expenses e JOIN financial_transactions t ON t.id = e.financial_transaction_id JOIN expense_categories c ON c.id = e.expense_category_id
             WHERE e.business_id = ? AND t.voided_at IS NULL AND e.expense_date >= ? AND e.expense_date <= ? GROUP BY c.id, c.name ORDER BY total DESC',
            [$biz, $fromDate, $toDate]
        );
        $expenses = Decimal::zero();
        foreach ($byCategory as $c) {
            $expenses = $expenses->add(Decimal::from($c['total'], 2));
        }
        $netRevenue = $revenue->sub($returns);
        $netCogs = $cogs->sub($returnedCost);
        $gross = $netRevenue->sub($netCogs);
        $net = $gross->sub($expenses);
        $margin = $netRevenue->isPositive() ? $gross->mul(Decimal::from(100, 2), 4)->div($netRevenue, 2)->toString() : null;

        return [
            'revenue' => $revenue, 'returns' => $returns, 'net_revenue' => $netRevenue, 'cogs' => $cogs, 'returned_cost' => $returnedCost, 'net_cogs' => $netCogs,
            'gross' => $gross, 'expenses' => $expenses, 'net' => $net, 'gross_margin_pct' => $margin, 'by_category' => $byCategory,
        ];
    }
}
