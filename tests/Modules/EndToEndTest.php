<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Audit\AuditLogger;
use Bic\Modules\Expenses\ExpenseService;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Inventory\InventoryService;
use Bic\Modules\Products\ProductRepository;
use Bic\Modules\Products\ProductService;
use Bic\Modules\Purchases\PurchaseService;
use Bic\Modules\Sales\SalesService;
use Bic\Modules\Suppliers\SupplierRepository;
use Bic\Modules\Suppliers\SupplierService;
use Bic\Tests\Support\DatabaseTestCase;

/** Un ciclo de negocio completo: compra → recepción → pago → venta con anticipo → entrega → devolución → gasto → resultados. */
final class EndToEndTest extends DatabaseTestCase
{
    private const NOW = '2026-02-10 15:00:00';
    private const TODAY = '2026-02-10';

    private function f(mixed $v): string
    {
        return number_format((float) (string) $v, 2, '.', '');
    }

    public function testFullBusinessCycleLeavesEverythingReconciled(): void
    {
        $actor = $this->actorFor($this->makeUser('admin'));
        $biz = $this->businessId();
        $audit = new AuditLogger($this->db, $this->clock);
        $inv = new InventoryService($this->db, $audit);
        $fin = new FinanceService($this->db, $audit);
        $po = new PurchaseService($this->db, $audit, $inv, $fin);
        $sales = new SalesService($this->db, $audit, $inv, $fin);
        $wh = (int) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');

        $acc = (int) $fin->saveAccount($actor, null, ['name' => 'Banco', 'type' => 'bank', 'opening_balance' => '500', 'is_active' => 1])['id'];
        $prod = (new ProductService($this->db, new ProductRepository($this->db), $audit))->create($actor, ['name' => 'Taza', 'unit_id' => (string) $this->db->value("SELECT id FROM units WHERE code = 'un'"), 'sale_price' => '12', 'sku' => 'T1', 'reference_cost' => '5', 'status' => 'active']);
        $this->assertTrue($prod['ok']);
        $v = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$prod['id']]);
        $sup = (int) (new SupplierService($this->db, new SupplierRepository($this->db), $audit))->save($actor, null, ['name' => 'Prov', 'currency' => 'USD', 'is_active' => 1])['id'];

        // Compra de 20 a 5,00 = 100,00; recepción completa y pago total.
        $o = (int) $po->save($actor, null, ['supplier_id' => (string) $sup, 'warehouse_id' => (string) $wh, 'currency' => 'USD', 'exchange_rate' => ''])['id'];
        $this->assertTrue($po->addLine($actor, $o, ['variant_id' => (string) $v, 'quantity' => '20', 'unit_price' => '5', 'tax_rate' => '0', 'tax_recoverable' => '1'])['ok']);
        $this->assertTrue($po->place($actor, $o, self::TODAY)['ok']);
        $lines = [];
        foreach ($po->lines($biz, $o) as $l) {
            $lines[(int) $l['id']] = '20';
        }
        $this->assertTrue($po->receive($actor, $o, $lines, self::TODAY, '', self::TODAY, self::NOW)['ok']);
        $this->assertTrue($po->pay($actor, $o, ['account_id' => (string) $acc, 'amount' => '100'], self::NOW)['ok']);
        $this->assertSame('400.00', $this->f($fin->balance($biz, $acc)->toString()));

        // Venta de 4 a 12,00 + 16 % = 55,68; anticipo 20 al confirmar y saldo al entregar.
        $s = (int) $sales->save($actor, null, ['warehouse_id' => (string) $wh])['id'];
        $this->assertTrue($sales->addLine($actor, $s, ['variant_id' => (string) $v, 'quantity' => '4', 'unit_price' => '12', 'tax_rate' => '16'])['ok']);
        foreach (['pending', 'confirmed'] as $step) {
            $this->assertTrue($sales->advance($actor, $s, $step, self::NOW)['ok']);
        }
        $this->assertTrue($sales->pay($actor, $s, ['account_id' => (string) $acc, 'amount' => '20', 'token' => 'e2e-1'], self::NOW)['ok']);
        $dup = $sales->pay($actor, $s, ['account_id' => (string) $acc, 'amount' => '20', 'token' => 'e2e-1'], self::NOW);
        $this->assertTrue($dup['ok'] && ($dup['duplicate'] ?? false), 'el doble clic no cobra dos veces');
        foreach (['prepared', 'delivered'] as $step) {
            $this->assertTrue($sales->advance($actor, $s, $step, self::NOW)['ok']);
        }
        $this->assertTrue($sales->pay($actor, $s, ['account_id' => (string) $acc, 'amount' => '35.68'], self::NOW)['ok']);
        $this->assertSame('455.68', $this->f($fin->balance($biz, $acc)->toString()));
        $this->assertSame('16.00', $this->f($this->db->value('SELECT quantity FROM stock_levels WHERE variant_id = ?', [$v])));

        // Devolución de 1 con reingreso, y un gasto de 7.
        $line = (int) $this->db->value('SELECT id FROM order_lines WHERE order_id = ?', [$s]);
        $this->assertTrue($sales->createReturn($actor, $s, ['qty' => [$line => '1'], 'reason' => 'No le gustó', 'restock' => true], self::TODAY, self::NOW)['ok']);
        $this->assertSame('17.00', $this->f($this->db->value('SELECT quantity FROM stock_levels WHERE variant_id = ?', [$v])));
        $exp = new ExpenseService($this->db, $audit, $fin);
        $cat = (int) $exp->categories($biz)[0]['id'];
        $this->assertTrue($exp->create($actor, ['category_id' => (string) $cat, 'account_id' => (string) $acc, 'amount' => '7', 'expense_date' => self::TODAY, 'description' => 'Flete'], self::TODAY, self::NOW)['ok']);

        // Resultados: ingresos 48−12 = 36; costo 4×5−1×5 = 15; bruta 21; neta 21−7 = 14.
        $p = $exp->profitAndLoss($biz, self::TODAY, self::TODAY, '2026-02-10 00:00:00', '2026-02-11 00:00:00');
        $this->assertSame(['36.00', '15.00', '21.00', '14.00'], [$this->f($p['net_revenue']), $this->f($p['net_cogs']), $this->f($p['gross']), $this->f($p['net'])]);

        // Integridad global.
        $this->assertSame([], $fin->reconcile($biz));
        $this->assertSame([], $inv->reconcile($biz));
        $this->assertSame([], $sales->reconcile($biz));
    }
}
