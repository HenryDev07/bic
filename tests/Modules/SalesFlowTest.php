<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Tests\Support\AppTestCase;

/** Flujo HTTP completo de clientes y pedidos (etapa 4e): permisos, páginas y transiciones. */
final class SalesFlowTest extends AppTestCase
{
    private function seedProduct(): int
    {
        $unit = (string) $this->db->value("SELECT id FROM units WHERE code = 'un'");
        $this->post('/products', ['name' => 'Gorra', 'unit_id' => $unit, 'sale_price' => '15', 'sku' => 'G1', 'reference_cost' => '6']);
        $v = (int) $this->db->value('SELECT id FROM product_variants');
        $wh = (string) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
        $this->post('/inventory/adjust', ['type' => 'in', 'warehouse_id' => $wh, 'variant_id' => (string) $v, 'quantity' => '10', 'unit_cost' => '6', 'reason' => 'Inicial']);

        return $v;
    }

    public function testFullOrderFlowAndPermissions(): void
    {
        $this->loginAs('admin');
        $v = $this->seedProduct();
        $this->assertSame(200, $this->get('/customers')->status);
        $this->assertSame(422, $this->post('/customers', ['name' => ''])->status);
        $this->assertSame(302, $this->post('/customers', ['name' => 'Ana Pérez'])->status);
        $cid = (int) $this->db->value('SELECT id FROM customers');
        $this->assertStringContainsString('Ana Pérez', $this->get('/customers/' . $cid)->body);

        $wh = (string) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
        $this->assertSame(302, $this->post('/sales', ['customer_id' => (string) $cid, 'warehouse_id' => $wh])->status);
        $oid = (int) $this->db->value('SELECT id FROM orders');
        $this->assertSame(302, $this->post("/sales/{$oid}/lines", ['variant_id' => (string) $v, 'quantity' => '2'])->status);
        $page = $this->get("/sales/{$oid}");
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('PE-', $page->body);
        $this->assertStringContainsString('30.00', $page->body);

        foreach (['submit', 'confirm', 'prepare'] as $step) {
            $this->assertSame(302, $this->post("/sales/{$oid}/{$step}")->status);
        }
        $this->assertSame('prepared', $this->db->value('SELECT status FROM orders'));
        $this->assertSame('2.000', number_format((float) $this->db->value('SELECT reserved_quantity FROM stock_levels'), 3, '.', ''));

        $acc = (string) $this->db->value('SELECT id FROM financial_accounts');
        if ($acc === '') {
            $this->post('/finance/accounts', ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '0', 'is_active' => '1']);
            $acc = (string) $this->db->value('SELECT id FROM financial_accounts');
        }
        $this->post("/sales/{$oid}/pay", ['account_id' => $acc, 'amount' => '10', 'token' => 'tok-1']);
        $this->post("/sales/{$oid}/pay", ['account_id' => $acc, 'amount' => '10', 'token' => 'tok-1']);
        $this->assertSame(1, $this->countRows('order_payments'), 'el doble envío no duplica el cobro');

        $this->post("/sales/{$oid}/deliver");
        $this->assertSame('delivered', $this->db->value('SELECT status FROM orders'));
        $this->assertSame('8.000', number_format((float) $this->db->value('SELECT quantity FROM stock_levels'), 3, '.', ''));
        $this->assertStringContainsString('Entregado', $this->get('/sales')->body);

        $line = (int) $this->db->value('SELECT id FROM order_lines');
        $this->post("/sales/{$oid}/return", ['reason' => 'Talla', 'restock' => '1', 'qty' => [$line => '1']]);
        $this->assertSame(1, $this->countRows('order_returns'));
        $this->assertSame(302, $this->post("/sales/{$oid}/cancel", ['reason' => 'x'])->status);
        $this->assertSame('delivered', $this->db->value('SELECT status FROM orders'), 'entregado no se cancela');
    }

    public function testSellerCanSellButNotCancelRefundOrReturn(): void
    {
        $this->loginAs('admin');
        $v = $this->seedProduct();
        $this->post('/logout');

        $this->loginAs('seller');
        $wh = (string) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
        $this->assertSame(302, $this->post('/sales', ['warehouse_id' => $wh])->status);
        $oid = (int) $this->db->value('SELECT id FROM orders');
        $this->post("/sales/{$oid}/lines", ['variant_id' => (string) $v, 'quantity' => '1']);
        $this->post("/sales/{$oid}/submit");
        $this->post("/sales/{$oid}/deliver");
        $this->assertSame('delivered', $this->db->value('SELECT status FROM orders'));
        $this->assertSame(403, $this->post("/sales/{$oid}/cancel", ['reason' => 'x'])->status);
        $this->assertSame(403, $this->post("/sales/{$oid}/refund", ['amount' => '1'])->status);
        $this->assertSame(403, $this->post("/sales/{$oid}/return", ['reason' => 'x'])->status);
        $this->assertSame(403, $this->post('/sales/reserve-setting', ['reserve_on' => 'never'])->status);
        $this->assertSame(404, $this->get('/sales/99999')->status);
    }
}
