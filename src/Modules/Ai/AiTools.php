<?php

declare(strict_types=1);

namespace Bic\Modules\Ai;

use Bic\Core\Audit\AuditLogger;
use Bic\Core\Database\Database;
use Bic\Core\Money\Decimal;
use Bic\Modules\Dashboard\DashboardService;
use Bic\Modules\Expenses\ExpenseService;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Insights\InsightService;
use Bic\Modules\Inventory\InventoryService;
use DateTimeImmutable;

/**
 * Catálogo CERRADO de herramientas de solo lectura que la IA puede invocar. No existe ninguna herramienta de SQL libre,
 * de escritura ni de acceso a credenciales: la IA solo recibe cifras agregadas ya calculadas por los módulos.
 * Cada herramienta exige permisos (se ofrece al modelo solo si el usuario los tiene y se vuelve a comprobar al ejecutar).
 */
final class AiTools
{
    public const PERIODS = [7, 30, 90, 180, 365];

    /** @var array<string,array{description:string,permissions:list<string>}> */
    private const DEFS = [
        'sales_summary'       => ['description' => 'Ventas netas (sin impuesto) del período, número de pedidos entregados y variación frente al período anterior.', 'permissions' => ['sales.view']],
        'profit_and_loss'     => ['description' => 'Estado de resultados del período: ingresos netos, costo, ganancia bruta, gastos por categoría y ganancia neta.', 'permissions' => ['expenses.view']],
        'top_products'        => ['description' => 'Productos más vendidos del período por ventas netas.', 'permissions' => ['sales.view']],
        'top_customers'       => ['description' => 'Mejores clientes del período por ventas netas.', 'permissions' => ['sales.view', 'customers.view']],
        'receivables'         => ['description' => 'Total por cobrar a clientes (pedidos entregados con saldo) y anticipos recibidos de pedidos sin entregar.', 'permissions' => ['sales.view']],
        'reorder_suggestions' => ['description' => 'Productos en o bajo su stock mínimo con cobertura en días y cantidad sugerida a reponer.', 'permissions' => ['inventory.view']],
        'slow_movers'         => ['description' => 'Existencias sin ventas en los días configurados (estancadas).', 'permissions' => ['inventory.view']],
        'inventory_value'     => ['description' => 'Valor total del inventario a costo promedio.', 'permissions' => ['inventory.view', 'products.cost']],
    ];

    /**
     * @param callable(string):bool $can
     * @param callable(int):array{from:string,to:string,fromUtc:string,toUtc:string,prevFromUtc:string} $bounds
     * @param callable(string):string $slowSince días → fecha UTC límite para «estancado»
     */
    public function __construct(
        private readonly Database $db,
        private readonly AuditLogger $audit,
        private readonly int $biz,
        private $can,
        private $bounds,
        private $slowSince,
    ) {
    }

    /** @return list<array{name:string,description:string,schema:array<string,mixed>}> herramientas que este usuario puede usar */
    public function available(): array
    {
        $out = [];
        foreach (self::DEFS as $name => $d) {
            if ($this->allowed($d['permissions'])) {
                $out[] = ['name' => $name, 'description' => $d['description'], 'schema' => $this->schema($name)];
            }
        }

        return $out;
    }

    /** @param list<string> $perms */
    private function allowed(array $perms): bool
    {
        foreach ($perms as $p) {
            if (!($this->can)($p)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function schema(string $name): array
    {
        $props = ['days' => ['type' => 'integer', 'enum' => self::PERIODS, 'description' => 'Días hacia atrás, incluido hoy (por defecto 30).']];
        if ($name === 'top_products') {
            $props['limit'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Cuántos productos (por defecto 5).'];
        }
        if (in_array($name, ['receivables', 'inventory_value', 'slow_movers'], true)) {
            unset($props['days']);
        }

        return ['type' => 'object', 'properties' => $props, 'additionalProperties' => false];
    }

    /**
     * Ejecuta una herramienta con argumentos validados. Nunca lanza por datos del modelo: devuelve ['error' => ...].
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run(string $name, array $args): array
    {
        $def = self::DEFS[$name] ?? null;
        if ($def === null) {
            return ['error' => 'Herramienta desconocida.'];
        }
        if (!$this->allowed($def['permissions'])) {
            return ['error' => 'No tienes permiso para esta consulta.'];
        }
        $days = 30;
        if (isset($args['days'])) {
            if (!is_int($args['days']) && !(is_string($args['days']) && ctype_digit($args['days']))) {
                return ['error' => 'El período debe ser un número de días.'];
            }
            $days = (int) $args['days'];
            if (!in_array($days, self::PERIODS, true)) {
                return ['error' => 'Período no válido. Usa uno de: ' . implode(', ', self::PERIODS) . '.'];
            }
        }
        $b = ($this->bounds)($days);
        $biz = $this->biz;
        $insights = new InsightService($this->db);
        $dash = new DashboardService($this->db);
        $meta = ['period' => $b['from'] . ' a ' . $b['to'], 'currency' => 'USD', 'note' => 'Importes sin impuesto salvo indicación.'];

        switch ($name) {
            case 'sales_summary':
                $t = $insights->trend($biz, $b['fromUtc'], $b['toUtc'], $b['prevFromUtc']);
                $orders = (int) $this->db->value("SELECT COUNT(*) FROM orders WHERE business_id = ? AND status = 'delivered' AND delivered_at >= ? AND delivered_at < ?", [$biz, $b['fromUtc'], $b['toUtc']]);

                return $meta + ['net_sales' => $t['current']->toString(), 'previous_period_net_sales' => $t['previous']->toString(), 'change_pct' => $t['change_pct'], 'delivered_orders' => $orders];
            case 'profit_and_loss':
                $svc = new ExpenseService($this->db, $this->audit, new FinanceService($this->db, $this->audit));
                $p = $svc->profitAndLoss($biz, $b['from'], $b['to'], $b['fromUtc'], $b['toUtc']);
                $canCost = ($this->can)('products.cost');
                $out = $meta + ['net_revenue' => $p['net_revenue']->toString(), 'expenses_total' => $p['expenses']->toString(),
                    'expenses_by_category' => array_map(static fn (array $c): array => ['category' => $c['name'], 'total' => Decimal::from($c['total'], 2)->toString()], $p['by_category'])];
                if ($canCost) {
                    $out += ['cost_of_goods' => $p['net_cogs']->toString(), 'gross_profit' => $p['gross']->toString(), 'gross_margin_pct' => $p['gross_margin_pct'], 'net_profit' => $p['net']->toString()];
                } else {
                    $out['note'] .= ' Costos y ganancias no disponibles con tu rol.';
                }

                return $out;
            case 'top_products':
                $limit = isset($args['limit']) ? (int) $args['limit'] : 5;
                $limit = max(1, min(10, $limit));

                return $meta + ['products' => array_map(static fn (array $r): array => ['name' => $r['name'], 'units' => Decimal::from($r['qty'], 3)->toString(), 'net_sales' => Decimal::from($r['net'], 2)->toString()], $dash->topProducts($biz, $b['fromUtc'], $b['toUtc'], $limit))];
            case 'top_customers':
                return $meta + ['customers' => array_map(static fn (array $r): array => ['name' => $r['name'], 'orders' => (int) $r['orders'], 'net_sales' => Decimal::from($r['net'], 2)->toString()], $insights->topCustomers($biz, $b['fromUtc'], $b['toUtc']))];
            case 'receivables':
                return ['currency' => 'USD', 'receivable_total' => $dash->receivables($biz)->toString(), 'customer_deposits_on_open_orders' => $dash->deposits($biz)->toString()];
            case 'reorder_suggestions':
                return $meta + ['products' => array_map(static fn (array $r): array => ['name' => $r['name'], 'available' => $r['available'], 'minimum' => $r['min'], 'days_of_cover' => $r['cover_days'], 'suggested_units' => $r['suggested']], $insights->reorder($biz, $b['fromUtc'], $b['toUtc'], $days))];
            case 'slow_movers':
                $canCost = ($this->can)('products.cost');
                $since = ($this->slowSince)('x');

                return ['currency' => 'USD', 'products' => array_map(static fn (array $r): array => ['name' => $r['name'], 'units' => $r['qty'], 'last_sale' => $r['last_sale'], 'tied_up_capital' => $r['value']], $insights->slowMovers($biz, $since, $canCost))];
            case 'inventory_value':
                $v = (new InventoryService($this->db, $this->audit))->valuation($biz);

                return ['currency' => 'USD', 'inventory_value_at_average_cost' => $v['value'], 'stock_lines' => $v['levels']];
        }

        return ['error' => 'Herramienta desconocida.'];
    }
}
