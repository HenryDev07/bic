<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Audit\AuditLogger;
use Bic\Modules\Customers\CustomerService;
use Bic\Modules\Finance\FinanceService;
use Bic\Modules\Inventory\InventoryService;
use Bic\Modules\Products\ProductRepository;
use Bic\Modules\Products\ProductService;
use Bic\Modules\Sales\SalesService;
use Bic\Tests\Support\DatabaseTestCase;

final class SalesServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-02-10 15:00:00';
    private const TODAY = '2026-02-10';

    private array $actor;
    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->actorFor($this->makeUser('admin'));
        $this->account = (int) $this->fin()->saveAccount($this->actor, null, ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '1000', 'is_active' => 1])['id'];
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

    private function sales(): SalesService
    {
        return new SalesService($this->db, $this->audit(), $this->inv(), $this->fin());
    }

    private function wh(): int
    {
        return (int) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
    }

    /** Variante con stock inicial $qty a costo $cost y precio de lista $price (sin impuesto, tasa del producto). */
    private function variant(string $sku, string $qty, string $cost, string $price = '30'): int
    {
        $svc = new ProductService($this->db, new ProductRepository($this->db), $this->audit());
        $r = $svc->create($this->actor, ['name' => 'Prod ' . $sku, 'unit_id' => (string) $this->db->value("SELECT id FROM units WHERE code = 'un'"), 'sale_price' => $price, 'sku' => $sku, 'reference_cost' => '1', 'status' => 'active']);
        $this->assertTrue($r['ok'], json_encode($r['errors'] ?? []));
        $v = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$r['id']]);
        if ($qty !== '0') {
            $a = $this->inv()->adjust($this->actor, ['type' => 'in', 'warehouse_id' => (string) $this->wh(), 'variant_id' => (string) $v, 'quantity' => $qty, 'unit_cost' => $cost, 'reason' => 'Inicial'], self::NOW);
            $this->assertTrue($a['ok']);
        }

        return $v;
    }

    private function draft(array $lines, ?int $customer = null): int
    {
        $r = $this->sales()->save($this->actor, null, ['customer_id' => (string) ($customer ?? ''), 'warehouse_id' => (string) $this->wh()]);
        $this->assertTrue($r['ok'], json_encode($r['errors']));
        foreach ($lines as $variant => $spec) {
            [$qty, $price, $tax] = $spec + [1 => '', 2 => '0'];
            $l = $this->sales()->addLine($this->actor, $r['id'], ['variant_id' => (string) $variant, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => $tax]);
            $this->assertTrue($l['ok'], json_encode($l['errors']));
        }

        return (int) $r['id'];
    }

    private function go(int $id, string ...$steps): void
    {
        foreach ($steps as $to) {
            $r = $this->sales()->advance($this->actor, $id, $to, self::NOW);
            $this->assertTrue($r['ok'], (string) $r['error']);
        }
    }

    private function lvl(int $v): array
    {
        return $this->db->first('SELECT quantity, reserved_quantity, avg_cost FROM stock_levels WHERE variant_id = ?', [$v]);
    }

    private function n(mixed $v, int $d = 2): string
    {
        return number_format((float) (string) $v, $d, '.', '');
    }

    private function bal(): string
    {
        return $this->fin()->balance($this->businessId(), $this->account)->toString();
    }

    private function totals(int $id): array
    {
        $o = $this->sales()->find($this->businessId(), $id);

        return array_map(static fn ($d) => $d->toString(), $this->sales()->totals($o, $this->businessId()));
    }

    // ---------- tests ----------

    public function testOrderNumberAndLineTaxMath(): void
    {
        $v = $this->variant('S1', '10', '5');
        $id = $this->draft([$v => ['3', '10.00', '16']]);
        $o = $this->sales()->find($this->businessId(), $id);
        $this->assertSame(sprintf('PE-%05d', $id), $o['order_number']);
        $l = $this->sales()->lines($this->businessId(), $id)[0];
        $this->assertSame(['30.00', '4.80', '34.80'], [$this->n($l['net_amount']), $this->n($l['tax_amount']), $this->n($l['line_total'])]);
        $t = $this->totals($id);
        $this->assertSame('34.80', $this->n($t['total']));
        $this->assertSame('34.80', $this->n($t['balance']));
    }

    public function testLinesEditableOnlyInDraftAndNoDuplicates(): void
    {
        $v = $this->variant('S2', '10', '5');
        $id = $this->draft([$v => ['1', '10']]);
        $dup = $this->sales()->addLine($this->actor, $id, ['variant_id' => (string) $v, 'quantity' => '1']);
        $this->assertTrue(isset($dup['errors']['variant_id']));
        $this->go($id, 'pending');
        $this->assertFalse($this->sales()->addLine($this->actor, $id, ['variant_id' => (string) $v, 'quantity' => '1'])['ok']);
        $line = $this->sales()->lines($this->businessId(), $id)[0];
        $this->assertFalse($this->sales()->removeLine($this->actor, $id, (int) $line['id'])['ok']);
    }

    public function testInvalidTransitionsAndEmptyOrder(): void
    {
        $v = $this->variant('S3', '10', '5');
        $empty = (int) $this->sales()->save($this->actor, null, ['warehouse_id' => (string) $this->wh()])['id'];
        $this->assertFalse($this->sales()->advance($this->actor, $empty, 'pending', self::NOW)['ok'], 'sin líneas');
        $id = $this->draft([$v => ['1', '10']]);
        $this->assertFalse($this->sales()->advance($this->actor, $id, 'delivered', self::NOW)['ok'], 'draft→delivered no permitido');
        $this->assertFalse($this->sales()->advance($this->actor, $id, 'prepared', self::NOW)['ok']);
    }

    public function testReservationAtConfirmedThenDeliveryConsumesAndFreezesCost(): void
    {
        $v = $this->variant('S4', '10', '5');
        $id = $this->draft([$v => ['4', '10', '0']]);
        $this->go($id, 'pending');
        $this->assertSame('0.000', $this->n($this->lvl($v)['reserved_quantity'], 3), 'por defecto se reserva al confirmar');
        $this->go($id, 'confirmed');
        $this->assertSame('4.000', $this->n($this->lvl($v)['reserved_quantity'], 3));
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
        $this->go($id, 'prepared', 'delivered');
        $l = $this->lvl($v);
        $this->assertSame(['6.000', '0.000'], [$this->n($l['quantity'], 3), $this->n($l['reserved_quantity'], 3)]);
        $line = $this->sales()->lines($this->businessId(), $id)[0];
        $this->assertSame('5.0000', $this->n($line['unit_cost_snapshot'], 4));
        $o = $this->sales()->find($this->businessId(), $id);
        $this->assertSame('delivered', $o['status']);
        $this->assertTrue($o['delivered_at'] !== null);
        $t = $this->totals($id);
        $this->assertSame(['40.00', '20.00', '20.00'], [$this->n($t['total']), $this->n($t['cost']), $this->n($t['profit'])]);
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
        $this->assertSame([], $this->sales()->reconcile($this->businessId()));
    }

    public function testReservationBlocksOverselling(): void
    {
        $v = $this->variant('S5', '5', '5');
        $a = $this->draft([$v => ['4', '10']]);
        $b = $this->draft([$v => ['3', '10']]);
        $this->go($a, 'pending', 'confirmed');
        $this->go($b, 'pending');
        $r = $this->sales()->advance($this->actor, $b, 'confirmed', self::NOW);
        $this->assertFalse($r['ok']);
        $this->assertSame('pending', $this->sales()->find($this->businessId(), $b)['status'], 'la transición falla completa');
        $this->assertSame('4.000', $this->n($this->lvl($v)['reserved_quantity'], 3));
    }

    public function testReserveSettingPendingAndNever(): void
    {
        $v = $this->variant('S6', '10', '5');
        $this->assertSame('confirmed', $this->sales()->reserveOn($this->businessId()));
        $this->assertFalse($this->sales()->setReserveOn($this->actor, 'raro'));
        $this->assertTrue($this->sales()->setReserveOn($this->actor, 'pending'));
        $id = $this->draft([$v => ['2', '10']]);
        $this->go($id, 'pending');
        $this->assertSame('2.000', $this->n($this->lvl($v)['reserved_quantity'], 3));
        $this->go($id, 'confirmed'); // no se reserva dos veces
        $this->assertSame('2.000', $this->n($this->lvl($v)['reserved_quantity'], 3));
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));

        $this->assertTrue($this->sales()->setReserveOn($this->actor, 'never'));
        $w = $this->variant('S7', '10', '5');
        $id2 = $this->draft([$w => ['2', '10']]);
        $this->go($id2, 'pending', 'confirmed');
        $this->assertSame('0.000', $this->n($this->lvl($w)['reserved_quantity'], 3));
        $this->go($id2, 'delivered');
        $this->assertSame('8.000', $this->n($this->lvl($w)['quantity'], 3));
    }

    public function testCounterSaleDeliversWithoutReservationAndFailsWithoutStock(): void
    {
        $v = $this->variant('S8', '2', '5');
        $id = $this->draft([$v => ['3', '10']]);
        $this->go($id, 'pending');
        $r = $this->sales()->advance($this->actor, $id, 'delivered', self::NOW);
        $this->assertFalse($r['ok']);
        $this->assertSame('2.000', $this->n($this->lvl($v)['quantity'], 3));
        $this->assertSame(0, $this->countRows('stock_movements', "movement_type = 'sale'"));
        $ok = $this->draft([$v => ['2', '10']]);
        $this->go($ok, 'pending', 'delivered');
        $this->assertSame('0.000', $this->n($this->lvl($v)['quantity'], 3));
    }

    public function testCancelReleasesReservationAndRequiresDepositRefund(): void
    {
        $v = $this->variant('S9', '10', '5');
        $id = $this->draft([$v => ['5', '10']]);
        $this->go($id, 'pending', 'confirmed');
        $pay = $this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '20'], self::NOW);
        $this->assertTrue($pay['ok'], json_encode($pay['errors']));
        $this->assertSame('deposit', $this->db->value('SELECT kind FROM order_payments WHERE id = ?', [$pay['id']]));
        $this->assertSame('1020.00', $this->bal());

        $c = $this->sales()->cancel($this->actor, $id, 'Cliente desistió', self::NOW);
        $this->assertFalse($c['ok'], 'anticipo pendiente de reembolso');
        $this->assertSame('5.000', $this->n($this->lvl($v)['reserved_quantity'], 3));

        $this->assertTrue($this->sales()->refund($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '20'], self::NOW)['ok']);
        $this->assertSame('1000.00', $this->bal());
        $this->assertTrue($this->sales()->cancel($this->actor, $id, 'Cliente desistió', self::NOW)['ok']);
        $this->assertSame('0.000', $this->n($this->lvl($v)['reserved_quantity'], 3));
        $this->assertSame('cancelled', $this->sales()->find($this->businessId(), $id)['status']);
        $this->assertFalse($this->sales()->advance($this->actor, $id, 'delivered', self::NOW)['ok']);
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
        $this->assertSame([], $this->fin()->reconcile($this->businessId()));
    }

    public function testCancelRequiresReasonAndDeliveredCannotBeCancelled(): void
    {
        $v = $this->variant('S10', '10', '5');
        $id = $this->draft([$v => ['1', '10']]);
        $this->assertFalse($this->sales()->cancel($this->actor, $id, '  ', self::NOW)['ok']);
        $this->go($id, 'pending', 'delivered');
        $this->assertFalse($this->sales()->cancel($this->actor, $id, 'tarde', self::NOW)['ok']);
    }

    public function testPaymentsCappedAndPartial(): void
    {
        $v = $this->variant('S11', '10', '5');
        $id = $this->draft([$v => ['2', '10']]);
        $this->assertFalse($this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '5'], self::NOW)['ok'], 'borrador');
        $this->go($id, 'pending');
        $this->assertFalse($this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '20.01'], self::NOW)['ok'], 'más del total');
        $this->assertTrue($this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '12.50'], self::NOW)['ok']);
        $this->assertFalse($this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '7.51'], self::NOW)['ok']);
        $this->assertTrue($this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '7.50'], self::NOW)['ok']);
        $this->go($id, 'delivered');
        $t = $this->totals($id);
        $this->assertSame(['20.00', '0.00'], [$this->n($t['net_paid']), $this->n($t['balance'])]);
        $this->assertFalse($this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '1'], self::NOW)['ok']);
    }

    public function testPaymentAfterDeliveryIsPaymentKindAndForeignAccountRejected(): void
    {
        $v = $this->variant('S12', '10', '5');
        $id = $this->draft([$v => ['1', '10']]);
        $this->go($id, 'pending', 'delivered');
        $this->assertTrue(isset($this->sales()->pay($this->actor, $id, ['account_id' => '99999', 'amount' => '10'], self::NOW)['errors']['account_id']));
        $p = $this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '10'], self::NOW);
        $this->assertSame('payment', $this->db->value('SELECT kind FROM order_payments WHERE id = ?', [$p['id']]));
    }

    public function testIdempotentPaymentToken(): void
    {
        $v = $this->variant('S13', '10', '5');
        $id = $this->draft([$v => ['2', '10']]);
        $this->go($id, 'pending');
        $in = ['account_id' => (string) $this->account, 'amount' => '5', 'token' => 'abc123'];
        $a = $this->sales()->pay($this->actor, $id, $in, self::NOW);
        $b = $this->sales()->pay($this->actor, $id, $in, self::NOW);
        $this->assertTrue($a['ok'] && $b['ok']);
        $this->assertTrue(!empty($b['duplicate']));
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, $this->countRows('order_payments'));
        $this->assertSame('1005.00', $this->bal());
    }

    public function testVoidPaymentFromOrderOnly(): void
    {
        $v = $this->variant('S14', '10', '5');
        $id = $this->draft([$v => ['2', '10']]);
        $this->go($id, 'pending');
        $p = $this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '10'], self::NOW);
        $tx = (int) $this->db->value('SELECT financial_transaction_id FROM order_payments WHERE id = ?', [$p['id']]);
        $this->assertFalse($this->fin()->void($this->actor, $tx, 'directo', self::NOW)['ok'], 'desde el libro no se puede');
        $this->assertTrue($this->sales()->refund($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '4'], self::NOW)['ok']);
        $this->assertFalse($this->sales()->voidPayment($this->actor, $id, (int) $p['id'], 'error', self::NOW)['ok'], 'hay un reembolso que depende');
        $refund = (int) $this->db->value("SELECT id FROM order_payments WHERE kind = 'refund'");
        $this->assertTrue($this->sales()->voidPayment($this->actor, $id, $refund, 'error', self::NOW)['ok']);
        $this->assertTrue($this->sales()->voidPayment($this->actor, $id, (int) $p['id'], 'error', self::NOW)['ok']);
        $this->assertSame('1000.00', $this->bal());
        $this->assertSame('20.00', $this->n($this->totals($id)['balance']));
    }

    public function testRefundCannotExceedNetPaid(): void
    {
        $v = $this->variant('S15', '10', '5');
        $id = $this->draft([$v => ['2', '10']]);
        $this->go($id, 'pending');
        $this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '10'], self::NOW);
        $this->assertFalse($this->sales()->refund($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '10.01'], self::NOW)['ok']);
    }

    public function testReturnProportionalWithRemainderAndRestockAtOriginalCost(): void
    {
        $v = $this->variant('S16', '10', '5');
        $id = $this->draft([$v => ['3', '10.00', '16']]); // 30.00 + 4.80 = 34.80
        $this->go($id, 'pending', 'delivered');
        $line = (int) $this->sales()->lines($this->businessId(), $id)[0]['id'];
        // el costo promedio cambia después de entregar: la devolución repone al costo original (5.00)
        $this->inv()->adjust($this->actor, ['type' => 'in', 'warehouse_id' => (string) $this->wh(), 'variant_id' => (string) $v, 'quantity' => '10', 'unit_cost' => '9', 'reason' => 'Compra'], self::NOW);

        $r1 = $this->sales()->createReturn($this->actor, $id, ['qty' => [$line => '1'], 'reason' => 'Defectuoso', 'restock' => true], self::TODAY, self::NOW);
        $this->assertTrue($r1['ok'], json_encode($r1['errors']));
        $this->assertSame(['10.00', '1.60', '11.60'], array_map(fn ($c) => $this->n($this->db->value("SELECT {$c} FROM order_return_lines WHERE return_id = ?", [$r1['id']])), ['net_amount', 'tax_amount', 'line_total']));
        $r2 = $this->sales()->createReturn($this->actor, $id, ['qty' => [$line => '2'], 'reason' => 'Resto', 'restock' => false], self::TODAY, self::NOW);
        $this->assertTrue($r2['ok']);
        $sum = $this->db->first('SELECT SUM(net_amount) AS n, SUM(tax_amount) AS t, SUM(line_total) AS s FROM order_return_lines');
        $this->assertSame(['30.00', '4.80', '34.80'], [$this->n($sum['n']), $this->n($sum['t']), $this->n($sum['s'])], 'devolver todo = importe original exacto');
        $this->assertFalse($this->sales()->createReturn($this->actor, $id, ['qty' => [$line => '1'], 'reason' => 'Otra'], self::TODAY, self::NOW)['ok'], 'no queda nada por devolver');

        $mv = $this->db->first("SELECT quantity, unit_cost FROM stock_movements WHERE movement_type = 'return'");
        $this->assertSame(['1.000', '5.0000'], [$this->n($mv['quantity'], 3), $this->n($mv['unit_cost'], 4)]);
        $this->assertSame(1, $this->countRows('stock_movements', "movement_type = 'return'"), 'la segunda no repone');
        $t = $this->totals($id);
        $this->assertSame(['0.00', '0.00'], [$this->n($t['total']), $this->n($t['cost'])]);
        $this->assertSame([], $this->inv()->reconcile($this->businessId()));
        $this->assertSame([], $this->sales()->reconcile($this->businessId()));
    }

    public function testReturnValidation(): void
    {
        $v = $this->variant('S17', '10', '5');
        $id = $this->draft([$v => ['2', '10']]);
        $line = (int) $this->sales()->lines($this->businessId(), $id)[0]['id'];
        $this->assertFalse($this->sales()->createReturn($this->actor, $id, ['qty' => [$line => '1'], 'reason' => 'x'], self::TODAY, self::NOW)['ok'], 'no entregado');
        $this->go($id, 'pending', 'delivered');
        $this->assertTrue(isset($this->sales()->createReturn($this->actor, $id, ['qty' => [$line => '1']], self::TODAY, self::NOW)['errors']['reason']));
        $this->assertFalse($this->sales()->createReturn($this->actor, $id, ['qty' => [$line => '3'], 'reason' => 'x'], self::TODAY, self::NOW)['ok'], 'más de lo vendido');
        $this->assertSame(0, $this->countRows('order_returns'), 'la falla no deja devoluciones a medias');
        $this->assertTrue(isset($this->sales()->createReturn($this->actor, $id, ['qty' => []], self::TODAY, self::NOW)['errors']['qty']));
    }

    public function testReturnCreatesCustomerCreditThenRefund(): void
    {
        $v = $this->variant('S18', '10', '5');
        $id = $this->draft([$v => ['2', '10']]);
        $this->go($id, 'pending', 'delivered');
        $this->sales()->pay($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '20'], self::NOW);
        $line = (int) $this->sales()->lines($this->businessId(), $id)[0]['id'];
        $this->sales()->createReturn($this->actor, $id, ['qty' => [$line => '1'], 'reason' => 'x', 'restock' => true], self::TODAY, self::NOW);
        $t = $this->totals($id);
        $this->assertSame('-10.00', $this->n($t['balance']), 'saldo a favor del cliente');
        $this->assertTrue($this->sales()->refund($this->actor, $id, ['account_id' => (string) $this->account, 'amount' => '10'], self::NOW)['ok']);
        $this->assertSame('0.00', $this->n($this->totals($id)['balance']));
        $this->assertSame('1010.00', $this->bal());
    }

    public function testStatusHistoryAndTenantIsolation(): void
    {
        $v = $this->variant('S19', '10', '5');
        $id = $this->draft([$v => ['1', '10']]);
        $this->go($id, 'pending', 'confirmed', 'prepared', 'delivered');
        $h = $this->sales()->history($this->businessId(), $id);
        $this->assertSame(['draft', 'pending', 'confirmed', 'prepared', 'delivered'], array_column($h, 'to_status'));
        $this->assertNull($this->sales()->find($this->businessId() + 999, $id));
        $other = $this->actor;
        $other['business_id'] = $this->businessId() + 999;
        $this->assertSame(404, $this->sales()->advance($other, $id, 'delivered', self::NOW)['status']);
        $this->assertSame(404, $this->sales()->pay($other, $id, ['account_id' => '1', 'amount' => '1'], self::NOW)['status']);
    }

    public function testCustomerCrud(): void
    {
        $svc = new CustomerService($this->db, $this->audit());
        $this->assertTrue(isset($svc->save($this->actor, null, ['name' => ''])['errors']['name']));
        $this->assertTrue(isset($svc->save($this->actor, null, ['name' => 'Ana', 'email' => 'no'])['errors']['email']));
        $r = $svc->save($this->actor, null, ['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'is_active' => 1]);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $svc->paginate($this->businessId(), 'ana', 10, 0)['total']);
        $this->assertSame(404, $svc->save($this->actor, 99999, ['name' => 'X'])['status']);
        $id = $this->draft([$this->variant('S20', '5', '5') => ['1', '10']], (int) $r['id']);
        $this->assertSame('Ana Pérez', $this->sales()->find($this->businessId(), $id)['customer_name']);
    }
}
