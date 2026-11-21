<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Audit\AuditLogger;
use Bic\Modules\Expenses\ExpenseService;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Inventory\InventoryService;
use Bic\Modules\Products\ProductRepository;
use Bic\Modules\Products\ProductService;
use Bic\Modules\Research\ResearchService;
use Bic\Modules\Sales\SalesService;
use Bic\Tests\Support\DatabaseTestCase;

final class ExpensesResearchTest extends DatabaseTestCase
{
    private const NOW = '2026-02-10 15:00:00';
    private const TODAY = '2026-02-10';

    private array $actor;
    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->actorFor($this->makeUser('admin'));
        $this->account = (int) $this->fin()->saveAccount($this->actor, null, ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '100', 'is_active' => 1])['id'];
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger($this->db, $this->clock);
    }

    private function fin(): FinanceService
    {
        return new FinanceService($this->db, $this->audit());
    }

    private function exp(): ExpenseService
    {
        return new ExpenseService($this->db, $this->audit(), $this->fin());
    }

    private function res(): ResearchService
    {
        return new ResearchService($this->db, $this->audit());
    }

    private function expense(string $amount, string $cat = '', string $date = self::TODAY): array
    {
        $cats = $this->exp()->categories($this->businessId());

        return $this->exp()->create($this->actor, ['category_id' => (string) ($cat !== '' ? $cat : $cats[0]['id']), 'account_id' => (string) $this->account, 'amount' => $amount, 'expense_date' => $date, 'description' => 'Gasto'], self::TODAY, self::NOW);
    }

    private function pl(): array
    {
        return array_map(static fn ($v) => is_object($v) ? $v->toString() : $v, $this->exp()->profitAndLoss($this->businessId(), self::TODAY, self::TODAY, '2026-02-10 00:00:00', '2026-02-11 00:00:00'));
    }

    // ---------- gastos ----------

    public function testDefaultCategoriesAndAddCategory(): void
    {
        $this->assertCount(count(ExpenseService::DEFAULT_CATEGORIES), $this->exp()->categories($this->businessId()));
        $this->assertTrue($this->exp()->addCategory($this->actor, 'Seguros')['ok']);
        $this->assertFalse($this->exp()->addCategory($this->actor, 'seguros')['ok'], 'sin duplicados');
        $this->assertFalse($this->exp()->addCategory($this->actor, ' ')['ok']);
    }

    public function testExpenseMovesCashOneToOneAndVoidsFromDocument(): void
    {
        $r = $this->expense('25.50');
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        $this->assertSame('74.50', $this->fin()->balance($this->businessId(), $this->account)->toString());
        $e = $this->db->first('SELECT * FROM expenses');
        $tx = $this->db->first('SELECT * FROM financial_transactions WHERE id = ?', [$e['financial_transaction_id']]);
        $this->assertSame(['out', 'expense'], [$tx['direction'], $tx['source_type']]);
        $this->assertFalse($this->fin()->void($this->actor, (int) $tx['id'], 'directo', self::NOW)['ok'], 'desde el libro no');
        $this->assertTrue($this->exp()->void($this->actor, (int) $r['id'], 'Error', self::NOW)['ok']);
        $this->assertSame('100.00', $this->fin()->balance($this->businessId(), $this->account)->toString());
        $this->assertFalse($this->exp()->void($this->actor, (int) $r['id'], 'otra vez', self::NOW)['ok']);
        $this->assertSame([], $this->fin()->reconcile($this->businessId()));
    }

    public function testExpenseValidation(): void
    {
        $this->exp()->categories($this->businessId());
        $base = ['category_id' => (string) $this->db->value('SELECT id FROM expense_categories LIMIT 1'), 'account_id' => (string) $this->account, 'amount' => '5', 'expense_date' => self::TODAY, 'description' => 'x'];
        foreach ([['amount' => '0'], ['amount' => 'abc'], ['expense_date' => '2026-02-11'], ['expense_date' => '2026-13-40'], ['description' => ''], ['category_id' => '999'], ['account_id' => '999'], ['supplier_id' => '999']] as $bad) {
            $this->assertFalse($this->exp()->create($this->actor, $bad + $base, self::TODAY, self::NOW)['ok'], json_encode($bad));
        }
        $this->assertSame(0, $this->countRows('expenses'));
        $over = $this->exp()->create($this->actor, ['amount' => '100.01'] + $base, self::TODAY, self::NOW);
        $this->assertFalse($over['ok']);
        $this->assertStringContainsString('Saldo insuficiente', $over['errors']['amount']);
        $this->assertSame(0, $this->countRows('financial_transactions', "source_type = 'expense'"), 'sin saldo no deja asiento');
    }

    public function testPaginateSumIgnoresVoided(): void
    {
        $a = $this->expense('10');
        $this->expense('5', '', '2026-02-01');
        $this->exp()->void($this->actor, (int) $a['id'], 'x', self::NOW);
        $all = $this->exp()->paginate($this->businessId(), [], 10, 0);
        $this->assertSame([2, '5.00'], [$all['total'], number_format((float) $all['sum'], 2, '.', '')]);
        $this->assertSame(1, $this->exp()->paginate($this->businessId(), ['from' => '2026-02-05'], 10, 0)['total']);
    }

    // ---------- estado de resultados (caso hecho a mano) ----------

    public function testProfitAndLossMatchesHandCalculation(): void
    {
        $audit = $this->audit();
        $inv = new InventoryService($this->db, $audit);
        $sales = new SalesService($this->db, $audit, $inv, $this->fin());
        $prod = (new ProductService($this->db, new ProductRepository($this->db), $audit))->create($this->actor, ['name' => 'P', 'unit_id' => (string) $this->db->value("SELECT id FROM units WHERE code = 'un'"), 'sale_price' => '10', 'sku' => 'P1', 'reference_cost' => '1', 'status' => 'active']);
        $v = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$prod['id']]);
        $wh = (int) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
        $inv->adjust($this->actor, ['type' => 'in', 'warehouse_id' => (string) $wh, 'variant_id' => (string) $v, 'quantity' => '10', 'unit_cost' => '6', 'reason' => 'x'], self::NOW);
        $o = (int) $sales->save($this->actor, null, ['warehouse_id' => (string) $wh])['id'];
        $sales->addLine($this->actor, $o, ['variant_id' => (string) $v, 'quantity' => '3', 'unit_price' => '10', 'tax_rate' => '16']);
        $this->assertTrue($sales->advance($this->actor, $o, 'pending', self::NOW)['ok']);
        $this->assertTrue($sales->advance($this->actor, $o, 'delivered', self::NOW)['ok']);
        $line = (int) $this->db->value('SELECT id FROM order_lines');

        $p = $this->pl();
        $this->assertSame(['30.00', '18.00', '12.00', '12.00'], array_map(fn ($k) => number_format((float) $p[$k], 2, '.', ''), ['revenue', 'cogs', 'gross', 'net']), 'ventas 30 (sin impuesto) − costo 3×6');
        $this->assertSame('40.00', number_format((float) $p['gross_margin_pct'], 2, '.', ''));

        $this->expense('5');
        $this->assertSame('7.00', number_format((float) $this->pl()['net'], 2, '.', ''), 'neta = 12 − 5');

        $sales->createReturn($this->actor, $o, ['qty' => [$line => '1'], 'reason' => 'x', 'restock' => true], self::TODAY, self::NOW);
        $p = $this->pl(); // ingresos 20, costo 18−6=12, bruta 8, neta 3
        $this->assertSame(['20.00', '12.00', '8.00', '3.00'], array_map(fn ($k) => number_format((float) $p[$k], 2, '.', ''), ['net_revenue', 'net_cogs', 'gross', 'net']));

        $sales->createReturn($this->actor, $o, ['qty' => [$line => '1'], 'reason' => 'roto', 'restock' => false], self::TODAY, self::NOW);
        $p = $this->pl(); // ingresos 10, costo 12 (lo roto es pérdida), bruta −2, neta −7
        $this->assertSame(['10.00', '12.00', '-2.00', '-7.00'], array_map(fn ($k) => number_format((float) $p[$k], 2, '.', ''), ['net_revenue', 'net_cogs', 'gross', 'net']));

        $none = $this->exp()->profitAndLoss($this->businessId(), '2026-03-01', '2026-03-31', '2026-03-01 00:00:00', '2026-04-01 00:00:00');
        $this->assertNull($none['gross_margin_pct']);
        $this->assertSame('0.00', $none['net']->toString());
    }

    // ---------- investigación ----------

    public function testResearchSaveValidationAndAnalysis(): void
    {
        $this->assertTrue(isset($this->res()->save($this->actor, null, ['name' => ''])['errors']['name']));
        $this->assertTrue(isset($this->res()->save($this->actor, null, ['name' => 'X', 'status' => 'zzz'])['errors']['status']));
        $this->assertTrue(isset($this->res()->save($this->actor, null, ['name' => 'X', 'target_price' => '-1'])['errors']['target_price']));
        $r = $this->res()->save($this->actor, null, ['name' => 'Termo', 'estimated_cost' => '6', 'target_price' => '10']);
        $this->assertTrue($r['ok']);
        $id = (int) $r['id'];
        $this->assertSame(404, $this->res()->save($this->actor, 9999, ['name' => 'X'])['status']);
        foreach ([['A', '9'], ['B', '12'], ['C', '11']] as [$s, $p]) {
            $this->assertTrue($this->res()->addPrice($this->actor, $id, ['source' => $s, 'observed_price' => $p, 'observed_on' => self::TODAY], self::TODAY)['ok']);
        }
        $a = $this->res()->analysis($this->res()->find($this->businessId(), $id), $this->res()->prices($this->businessId(), $id));
        $this->assertSame(['9.00', '10.67', '12.00', '40.00', '66.67'], [$a['min'], $a['avg'], $a['max'], $a['margin'], $a['markup']]);
    }

    public function testResearchPriceValidationAndNoCrossTenant(): void
    {
        $id = (int) $this->res()->save($this->actor, null, ['name' => 'Y'])['id'];
        foreach ([['source' => '', 'observed_price' => '5'], ['source' => 'S', 'observed_price' => '0'], ['source' => 'S', 'observed_price' => '5', 'observed_on' => '2026-02-11'], ['source' => 'S', 'observed_price' => '5', 'url' => 'javascript:alert(1)']] as $bad) {
            $this->assertFalse($this->res()->addPrice($this->actor, $id, $bad, self::TODAY)['ok'], json_encode($bad));
        }
        $this->assertSame(0, $this->countRows('research_prices'));
        $other = $this->actor;
        $other['business_id'] = $this->businessId() + 99;
        $this->assertSame(404, $this->res()->addPrice($other, $id, ['source' => 'S', 'observed_price' => '5'], self::TODAY)['status']);
        $empty = $this->res()->analysis($this->res()->find($this->businessId(), $id), []);
        $this->assertSame([null, null, null], [$empty['min'], $empty['avg'], $empty['margin']]);
    }
}
