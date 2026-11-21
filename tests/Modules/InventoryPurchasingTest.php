<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Audit\AuditLogger;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Inventory\InventoryService;
use Bic\Modules\Products\ProductRepository;
use Bic\Modules\Products\ProductService;
use Bic\Modules\Purchases\PurchaseService;
use Bic\Modules\Suppliers\SupplierRepository;
use Bic\Modules\Suppliers\SupplierService;
use Bic\Tests\Support\DatabaseTestCase;

final class InventoryPurchasingTest extends DatabaseTestCase
{
    private const NOW = '2026-01-15 12:00:00';
    private const TODAY = '2026-01-15';

    private array $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->actorFor($this->makeUser('admin'));
    }

    private function audit(): AuditLogger
    {
        return new AuditLogger($this->db, $this->clock);
    }

    private function inv(): InventoryService
    {
        return new InventoryService($this->db, $this->audit());
    }

    private function fin(): FinanceService
    {
        return new FinanceService($this->db, $this->audit());
    }

    private function po(): PurchaseService
    {
        return new PurchaseService($this->db, $this->audit(), $this->inv(), $this->fin());
    }

    private function warehouse(): int
    {
        return (int) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
    }

    private function variant(string $sku, string $name = 'Producto'): int
    {
        $svc = new ProductService($this->db, new ProductRepository($this->db), $this->audit());
        $r = $svc->create($this->actor, ['name' => $name . ' ' . $sku, 'unit_id' => (string) $this->db->value("SELECT id FROM units WHERE code = 'un'"), 'sale_price' => '30', 'sku' => $sku, 'reference_cost' => '1', 'status' => 'active']);
        $this->assertTrue($r['ok']);

        return (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$r['id']]);
    }

    private function supplier(string $name = 'Prov', string $currency = 'USD'): int
    {
        $r = (new SupplierService($this->db, new SupplierRepository($this->db), $this->audit()))->save($this->actor, null, ['name' => $name, 'currency' => $currency, 'is_active' => 1]);
        $this->assertTrue($r['ok']);

        return (int) $r['id'];
    }

    /** @return array{0:int,1:array<string,string>} */
    private function adjust(array $in): array
    {
        $r = $this->inv()->adjust($this->actor, $in + ['warehouse_id' => (string) $this->warehouse()], self::NOW);

        return [$r['ok'] ? 1 : 0, $r['errors']];
    }

    private function level(int $variant, ?int $wh = null): array
    {
        return $this->db->first('SELECT * FROM stock_levels WHERE variant_id = ? AND warehouse_id = ?', [$variant, $wh ?? $this->warehouse()]) ?? ['quantity' => '0', 'avg_cost' => '0', 'reserved_quantity' => '0'];
    }

    private function n(mixed $v, int $d = 4): string
    {
        return number_format((float) $v, $d, '.', '');
    }

    /** Crea una orden con líneas [variant => [qty, price, tax, recoverable]] y la envía. */
    private function order(int $supplier, array $lines, string $currency = 'USD', string $rate = '', array $costs = []): int
    {
        $r = $this->po()->save($this->actor, null, ['supplier_id' => (string) $supplier, 'warehouse_id' => (string) $this->warehouse(), 'currency' => $currency, 'exchange_rate' => $rate]);
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        foreach ($lines as $variant => [$qty, $price, $tax, $rec]) {
            $l = $this->po()->addLine($this->actor, $r['id'], ['variant_id' => (string) $variant, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => $tax, 'tax_recoverable' => $rec]);
            $this->assertTrue($l['ok'], json_encode($l['errors']));
        }
        foreach ($costs as [$kind, $amount]) {
            $this->assertTrue($this->po()->addCostLine($this->actor, $r['id'], ['kind' => $kind, 'amount' => $amount])['ok']);
        }
        $this->assertTrue($this->po()->place($this->actor, $r['id'], self::TODAY)['ok']);

        return (int) $r['id'];
    }

    private function receive(int $order, array $qtyByVariant): array
    {
        $lines = [];
        foreach ($this->po()->lines($this->businessId(), $order) as $l) {
            if (isset($qtyByVariant[(int) $l['variant_id']])) {
                $lines[(int) $l['id']] = $qtyByVariant[(int) $l['variant_id']];
            }
        }

        return $this->po()->receive($this->actor, $order, $lines, self::TODAY, '', self::TODAY, self::NOW);
    }

    // ---------- inventario ----------

    public function testWeightedAverageCostAndOutflowsAtAverage(): void
    {
        $v = $this->variant('A');
        $this->assertSame(1, $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '10', 'unit_cost' => '5', 'reason' => 'Inicial'])[0]);
        $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '10', 'unit_cost' => '7', 'reason' => 'Compra']);
        $l = $this->level($v);
        $this->assertSame(['20.000', '6.0000'], [$this->n($l['quantity'], 3), $this->n($l['avg_cost'])]);

        $this->adjust(['type' => 'out', 'variant_id' => (string) $v, 'quantity' => '5', 'reason' => 'Merma']);
        $l = $this->level($v);
        $this->assertSame(['15.000', '6.0000'], [$this->n($l['quantity'], 3), $this->n($l['avg_cost'])], 'las salidas no cambian el promedio');
        $out = $this->db->first("SELECT * FROM stock_movements WHERE movement_type = 'adjustment_out'");
        $this->assertSame(['-5.000', '6.0000', '15.000'], [$this->n($out['quantity'], 3), $this->n($out['unit_cost']), $this->n($out['balance_after'], 3)]);
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
    }

    public function testEntryWithoutCostUsesCurrentAverageOrRequiresOne(): void
    {
        $v = $this->variant('B');
        [, $e] = $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '3', 'reason' => 'x']);
        $this->assertArrayHasKey('unit_cost', $e);
        $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '4', 'unit_cost' => '2.5', 'reason' => 'x']);
        $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '4', 'reason' => 'Conteo']);
        $this->assertSame(['8.000', '2.5000'], [$this->n($this->level($v)['quantity'], 3), $this->n($this->level($v)['avg_cost'])]);
    }

    public function testNoNegativeStockAndReservedStockIsProtected(): void
    {
        $v = $this->variant('C');
        $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '10', 'unit_cost' => '1', 'reason' => 'x']);
        [$ok, $e] = $this->adjust(['type' => 'out', 'variant_id' => (string) $v, 'quantity' => '10.001', 'reason' => 'x']);
        $this->assertSame(0, $ok);
        $this->assertStringContainsString('Stock insuficiente', $e['quantity']);

        $this->db->execute('UPDATE stock_levels SET reserved_quantity = 8 WHERE variant_id = ?', [$v]);
        $this->assertSame(0, $this->adjust(['type' => 'out', 'variant_id' => (string) $v, 'quantity' => '3', 'reason' => 'x'])[0], 'lo reservado no se puede sacar');
        $this->assertSame(1, $this->adjust(['type' => 'out', 'variant_id' => (string) $v, 'quantity' => '2', 'reason' => 'x'])[0]);
        $this->assertSame('8.000', $this->n($this->level($v)['quantity'], 3));
        $this->assertSame(2, $this->countRows('stock_movements'), 'los intentos fallidos no dejan rastro');
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
    }

    public function testAdjustValidation(): void
    {
        $v = $this->variant('D');
        $base = ['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '1', 'unit_cost' => '1', 'reason' => 'x'];
        $this->assertArrayHasKey('reason', $this->adjust(['reason' => ''] + $base)[1]);
        $this->assertArrayHasKey('quantity', $this->adjust(['quantity' => '0'] + $base)[1]);
        $this->assertArrayHasKey('variant_id', $this->adjust(['variant_id' => '999'] + $base)[1]);
        $this->assertArrayHasKey('warehouse_id', $this->adjust(['warehouse_id' => '999'] + $base)[1]);
        $this->assertArrayHasKey('type', $this->adjust(['type' => 'zzz'] + $base)[1]);
        $this->assertSame(0, $this->countRows('stock_movements'));
    }

    public function testTransferMovesAtAverageCostBetweenWarehouses(): void
    {
        $v = $this->variant('E');
        $b = $this->db->insert("INSERT INTO warehouses (business_id, code, name) VALUES (?, 'SUC1', 'Sucursal')", [$this->businessId()]);
        $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '10', 'unit_cost' => '4', 'reason' => 'x']);
        $r = $this->inv()->adjust($this->actor, ['type' => 'transfer', 'warehouse_id' => (string) $this->warehouse(), 'to_warehouse_id' => (string) $b, 'variant_id' => (string) $v, 'quantity' => '6', 'reason' => 'Reposición'], self::NOW);
        $this->assertTrue($r['ok']);
        $this->assertSame('4.000', $this->n($this->level($v)['quantity'], 3));
        $dest = $this->level($v, $b);
        $this->assertSame(['6.000', '4.0000'], [$this->n($dest['quantity'], 3), $this->n($dest['avg_cost'])]);
        $this->assertSame(1, $this->countRows('stock_movements', "movement_type = 'transfer_in' AND reference_type = 'transfer'"));

        $same = $this->inv()->adjust($this->actor, ['type' => 'transfer', 'warehouse_id' => (string) $b, 'to_warehouse_id' => (string) $b, 'variant_id' => (string) $v, 'quantity' => '1', 'reason' => 'x'], self::NOW);
        $this->assertArrayHasKey('to_warehouse_id', $same['errors']);
        $over = $this->inv()->adjust($this->actor, ['type' => 'transfer', 'warehouse_id' => (string) $b, 'to_warehouse_id' => (string) $this->warehouse(), 'variant_id' => (string) $v, 'quantity' => '7', 'reason' => 'x'], self::NOW);
        $this->assertFalse($over['ok']);
        $this->assertSame('6.000', $this->n($this->level($v, $b)['quantity'], 3), 'transferencia fallida: nada cambia');
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
    }

    public function testStockListLowStockAndValuation(): void
    {
        $v = $this->variant('F');
        $this->db->execute('UPDATE products SET min_stock = 5 WHERE id = (SELECT product_id FROM product_variants WHERE id = ?)', [$v]);
        $rows = $this->inv()->stock($this->businessId(), '', 0, true, 50, 0);
        $this->assertSame(1, $rows['total'], 'sin existencias y mínimo 5 → stock bajo');
        $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '12', 'unit_cost' => '2.5', 'reason' => 'x']);
        $this->assertSame(0, $this->inv()->stock($this->businessId(), '', 0, true, 50, 0)['total']);
        $r = $this->inv()->stock($this->businessId(), 'F', 0, false, 50, 0)['rows'][0];
        $this->assertSame(['12.000', '12.000', '30.00'], [$this->n($r['quantity'], 3), $this->n($r['available'], 3), $this->n($r['value'], 2)]);
        $this->assertSame('30.00', $this->inv()->valuation($this->businessId())['value']);
    }

    public function testDatabaseRejectsNegativeStock(): void
    {
        $v = $this->variant('G');
        $threw = false;
        try {
            $this->db->execute('INSERT INTO stock_levels (business_id, warehouse_id, variant_id, quantity) VALUES (?, ?, ?, -1)', [$this->businessId(), $this->warehouse(), $v]);
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw);
    }

    public function testReconcileDetectsTampering(): void
    {
        $v = $this->variant('H');
        $this->adjust(['type' => 'in', 'variant_id' => (string) $v, 'quantity' => '5', 'unit_cost' => '1', 'reason' => 'x']);
        $this->db->execute('UPDATE stock_levels SET quantity = 9 WHERE variant_id = ?', [$v]);
        $this->assertTrue(count($this->inv()->reconcile($this->businessId())) > 0);
    }

    // ---------- compras ----------

    public function testLandedCostAllocationAndNonRecoverableTax(): void
    {
        $a = $this->variant('PA');
        $b = $this->variant('PB');
        $s = $this->supplier();
        $o = $this->order($s, [$a => ['10', '10.00', '10', '1'], $b => ['5', '20.00', '10', '0']], 'USD', '', [['freight', '20'], ['duties', '10']]);

        $t = $this->po()->totals($this->po()->find($this->businessId(), $o), $this->businessId());
        $this->assertSame(['200.00', '20.00', '220.00', '30.00', '250.00'], [$t['subtotal']->toString(), $t['tax']->toString(), $t['goods_base']->toString(), $t['costs_base']->toString(), $t['payable']->toString()]);

        $r = $this->receive($o, [$a => '10', $b => '5']);
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        // A: 10 + 0 (recuperable) + 30×10/200 = 11.50 · B: 20 + 2.00 (10 % no recuperable) + 30×20/200 = 25.00
        $this->assertSame(['10.000', '11.5000'], [$this->n($this->level($a)['quantity'], 3), $this->n($this->level($a)['avg_cost'])]);
        $this->assertSame(['5.000', '25.0000'], [$this->n($this->level($b)['quantity'], 3), $this->n($this->level($b)['avg_cost'])]);
        $this->assertSame('received', $this->po()->find($this->businessId(), $o)['status']);
        $this->assertSame(2, $this->countRows('purchase_receipt_lines'));
        $this->assertSame(2, $this->countRows('stock_movements', "movement_type = 'purchase_receipt' AND reference_type = 'purchase_receipt'"));
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
        $this->assertSame(0, $this->countRows('financial_transactions'), 'recibir no mueve dinero: compra = inventario, no gasto');
    }

    public function testForeignCurrencyUsesDocumentExchangeRate(): void
    {
        $a = $this->variant('FX');
        $s = $this->supplier('Europa', 'EUR');
        $o = $this->order($s, [$a => ['10', '8', '0', '1']], 'EUR', '1.10');
        $t = $this->po()->totals($this->po()->find($this->businessId(), $o), $this->businessId());
        $this->assertSame(['80.00', '88.00'], [$t['total_doc']->toString(), $t['goods_base']->toString()]);
        $this->receive($o, [$a => '10']);
        $this->assertSame('8.8000', $this->n($this->level($a)['avg_cost']));
    }

    public function testPartialReceiptsAndWeightedAverageAcrossOrders(): void
    {
        $a = $this->variant('PR');
        $s = $this->supplier();
        $o1 = $this->order($s, [$a => ['10', '5', '0', '1']]);
        $this->assertTrue($this->receive($o1, [$a => '4'])['ok']);
        $this->assertSame('partial', $this->po()->find($this->businessId(), $o1)['status']);
        $this->assertFalse($this->receive($o1, [$a => '6.001'])['ok'], 'no se recibe más de lo pendiente');
        $this->assertSame('4.000', $this->n($this->level($a)['quantity'], 3), 'recepción rechazada: nada cambia');
        $this->assertTrue($this->receive($o1, [$a => '6'])['ok']);
        $this->assertSame('received', $this->po()->find($this->businessId(), $o1)['status']);
        $this->assertFalse($this->receive($o1, [$a => '1'])['ok']);

        $o2 = $this->order($s, [$a => ['10', '7', '0', '1']]);
        $this->receive($o2, [$a => '10']);
        $this->assertSame(['20.000', '6.0000'], [$this->n($this->level($a)['quantity'], 3), $this->n($this->level($a)['avg_cost'])]);
        $this->assertSame(3, $this->countRows('purchase_receipts'));
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
    }

    public function testCostLinesLockAfterFirstReceiptAndCancelRules(): void
    {
        $a = $this->variant('CL');
        $s = $this->supplier();
        $o = $this->order($s, [$a => ['10', '5', '0', '1']]);
        $this->assertTrue($this->po()->addCostLine($this->actor, $o, ['kind' => 'freight', 'amount' => '5'])['ok'], 'pedida y sin recepciones: editable');
        $this->assertArrayHasKey('amount', $this->po()->addCostLine($this->actor, $o, ['kind' => 'freight', 'amount' => '0'])['errors']);
        $this->receive($o, [$a => '3']);
        $this->assertFalse($this->po()->addCostLine($this->actor, $o, ['kind' => 'freight', 'amount' => '5'])['ok']);
        $this->assertFalse($this->po()->cancel($this->actor, $o, 'x')['ok'], 'con mercancía recibida no se cancela');

        $o2 = $this->order($s, [$a => ['1', '1', '0', '1']]);
        $this->assertFalse($this->po()->cancel($this->actor, $o2, '')['ok'], 'exige motivo');
        $this->assertTrue($this->po()->cancel($this->actor, $o2, 'Cambio de planes')['ok']);
        $this->assertFalse($this->receive($o2, [$a => '1'])['ok']);
    }

    public function testDraftRulesAndQuotePrefill(): void
    {
        $a = $this->variant('DR');
        $s = $this->supplier();
        $pid = (int) $this->db->value('SELECT product_id FROM product_variants WHERE id = ?', [$a]);
        (new SupplierService($this->db, new SupplierRepository($this->db), $this->audit()))->addQuote($this->actor, $s, ['product_id' => $pid, 'unit_price' => '3.25'], self::TODAY);

        $r = $this->po()->save($this->actor, null, ['supplier_id' => (string) $s, 'warehouse_id' => (string) $this->warehouse()]);
        $this->assertStringStartsWith('OC-', (string) $this->db->value('SELECT po_number FROM purchase_orders WHERE id = ?', [$r['id']]));
        $this->assertFalse($this->po()->place($this->actor, $r['id'], self::TODAY)['ok'], 'sin líneas no se envía');
        $this->assertTrue($this->po()->addLine($this->actor, $r['id'], ['variant_id' => (string) $a, 'quantity' => '2'])['ok']);
        $this->assertSame('3.2500', $this->n($this->db->value('SELECT unit_price FROM purchase_order_lines WHERE purchase_order_id = ?', [$r['id']])), 'precio vacío = última cotización');
        $this->assertArrayHasKey('variant_id', $this->po()->addLine($this->actor, $r['id'], ['variant_id' => (string) $a, 'quantity' => '1', 'unit_price' => '1'])['errors']);
        $this->assertArrayHasKey('quantity', $this->po()->addLine($this->actor, $r['id'], ['variant_id' => (string) $this->variant('DR2'), 'quantity' => '0', 'unit_price' => '1'])['errors']);

        $this->assertTrue($this->po()->place($this->actor, $r['id'], self::TODAY)['ok']);
        $this->assertFalse($this->po()->addLine($this->actor, $r['id'], ['variant_id' => (string) $this->variant('DR3'), 'quantity' => '1', 'unit_price' => '1'])['ok'], 'enviada = líneas bloqueadas');
        $this->assertFalse($this->po()->save($this->actor, $r['id'], ['supplier_id' => (string) $s, 'warehouse_id' => (string) $this->warehouse()])['ok']);
        $e = $this->po()->save($this->actor, null, ['supplier_id' => '999', 'warehouse_id' => '999', 'currency' => 'EUR'])['errors'];
        $this->assertSame(['supplier_id', 'warehouse_id', 'exchange_rate'], array_keys($e));
    }

    public function testPaymentsLinkToLedgerAndCannotExceedDue(): void
    {
        $a = $this->variant('PY');
        $s = $this->supplier();
        $o = $this->order($s, [$a => ['10', '10', '0', '1']], 'USD', '', [['freight', '10']]);
        $acc = $this->fin()->saveAccount($this->actor, null, ['name' => 'Banco', 'type' => 'bank', 'opening_balance' => '500', 'is_active' => 1])['id'];

        $this->assertArrayHasKey('amount', $this->po()->pay($this->actor, $o, ['account_id' => (string) $acc, 'amount' => '110.01'], self::NOW)['errors'], 'excede lo pendiente (100 + 10)');
        $p = $this->po()->pay($this->actor, $o, ['account_id' => (string) $acc, 'amount' => '60'], self::NOW);
        $this->assertTrue($p['ok']);
        $this->assertSame('440.00', $this->fin()->balance($this->businessId(), (int) $acc)->toString());
        $this->assertSame(1, $this->countRows('financial_transactions', "source_type = 'purchase_payment' AND direction = 'out'"));
        $t = $this->po()->totals($this->po()->find($this->businessId(), $o), $this->businessId());
        $this->assertSame(['60.00', '50.00'], [$t['paid']->toString(), $t['due']->toString()]);
        $this->assertFalse($this->po()->pay($this->actor, $o, ['account_id' => (string) $acc, 'amount' => '50.01'], self::NOW)['ok']);
        $this->assertSame([], $this->fin()->reconcile($this->businessId()));

        $this->assertFalse($this->po()->cancel($this->actor, $o, 'x')['ok'], 'con pagos no se cancela');
        $this->assertStringContainsString('otro documento', (string) $this->fin()->void($this->actor, (int) $this->db->value('SELECT financial_transaction_id FROM purchase_payments'), 'x', self::NOW)['error'], 'desde finanzas no se anula');
        $v = $this->po()->voidPayment($this->actor, $o, $p['id'], 'Pago duplicado', self::NOW);
        $this->assertTrue($v['ok']);
        $this->assertSame('500.00', $this->fin()->balance($this->businessId(), (int) $acc)->toString());
        $this->assertSame('110.00', $this->po()->totals($this->po()->find($this->businessId(), $o), $this->businessId())['due']->toString());
        $this->assertTrue($this->po()->pay($this->actor, $o, ['account_id' => (string) $acc, 'amount' => '110'], self::NOW)['ok'], 'tras anular se puede volver a pagar');
    }

    public function testPaymentNeedsFundsAndAnOpenOrder(): void
    {
        $a = $this->variant('PF');
        $s = $this->supplier();
        $acc = $this->fin()->saveAccount($this->actor, null, ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '5', 'is_active' => 1])['id'];
        $draft = $this->po()->save($this->actor, null, ['supplier_id' => (string) $s, 'warehouse_id' => (string) $this->warehouse()])['id'];
        $this->assertFalse($this->po()->pay($this->actor, $draft, ['account_id' => (string) $acc, 'amount' => '1'], self::NOW)['ok']);
        $o = $this->order($s, [$a => ['1', '50', '0', '1']]);
        $r = $this->po()->pay($this->actor, $o, ['account_id' => (string) $acc, 'amount' => '20'], self::NOW);
        $this->assertStringContainsString('Saldo insuficiente', $r['errors']['amount']);
        $this->assertSame(0, $this->countRows('purchase_payments'), 'nada queda a medias');
        $this->assertSame(0, $this->countRows('financial_transactions'));
    }

    public function testFinanceReconcileFlagsOrphanSupplierPayments(): void
    {
        $acc = $this->fin()->saveAccount($this->actor, null, ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '5', 'is_active' => 1])['id'];
        $this->db->execute("INSERT INTO financial_transactions (business_id, account_id, direction, amount, occurred_at, source_type, description) VALUES (?, ?, 'out', 1, ?, 'purchase_payment', 'huérfano')", [$this->businessId(), $acc, self::NOW]);
        $this->assertCount(1, $this->fin()->reconcile($this->businessId()));
    }
}
