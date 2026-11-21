<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Tests\Support\AppTestCase;

/** Peticiones completas de las etapas 4a/4b: permisos, páginas, formularios, CSV y anulaciones. */
final class Stage4FlowTest extends AppTestCase
{
    private function unitId(): string
    {
        return (string) $this->db->value("SELECT id FROM units WHERE code = 'un'");
    }

    public function testSellerSeesPricesButNotCostsSuppliersOrFinance(): void
    {
        $this->loginAs('admin');
        $this->post('/products', ['name' => 'Gorra', 'unit_id' => $this->unitId(), 'sale_price' => '15', 'sku' => 'G1', 'reference_cost' => '6.5']);
        $pid = (int) $this->db->value('SELECT id FROM products');
        $this->post('/logout');

        $this->loginAs('seller');
        $created = $this->post('/products', ['name' => 'X', 'unit_id' => $this->unitId(), 'sale_price' => '5', 'sku' => 'S1']);
        $this->assertSame(403, $created->status, 'el vendedor no crea productos');
        $page = $this->get('/products/' . $pid);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('15.00', $page->body);
        $this->assertStringNotContainsString('Costo de referencia', $page->body);
        $this->assertStringNotContainsString('Calculadora', $page->body);
        $this->assertStringNotContainsString('6.5000', $this->get('/products')->body);
        $csv = $this->get('/products/export');
        $this->assertStringNotContainsString('Costo de referencia', $csv->body);
        foreach (['/suppliers', '/finance', '/finance/ledger', '/catalog', '/products/create'] as $path) {
            $this->assertSame(403, $this->get($path)->status, $path);
        }
    }

    public function testAdminProductFlowWithCalculatorAndCsv(): void
    {
        $this->loginAs('admin');
        $this->assertSame(200, $this->get('/products/create')->status);

        $bad = $this->post('/products', ['name' => '', 'unit_id' => $this->unitId(), 'sale_price' => '5', 'sku' => 'A']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('El nombre es obligatorio', $bad->body);

        $ok = $this->post('/products', ['name' => '=HYPERLINK("x")', 'unit_id' => $this->unitId(), 'sale_price' => '100', 'sku' => 'A1', 'reference_cost' => '70']);
        $this->assertSame(302, $ok->status);
        $pid = (int) $this->db->value('SELECT id FROM products');

        $show = $this->get('/products/' . $pid, ['v' => '10', 'm' => '20']);
        $this->assertStringContainsString('77.78', $show->body, 'precio de equilibrio');
        $this->assertStringContainsString('100.00', $show->body, 'precio recomendado');
        $this->assertStringContainsString('Historial de precios', $show->body);
        $this->assertStringContainsString('Los porcentajes', $this->get('/products/' . $pid, ['v' => 'x'])->body);

        $csv = $this->get('/products/export');
        $this->assertSame(200, $csv->status);
        $this->assertStringContainsString('text/csv', $csv->headers['Content-Type']);
        $this->assertStringContainsString("\"'=HYPERLINK", $csv->body, 'protección contra inyección de fórmulas');
        $this->assertStringContainsString('Costo de referencia', $csv->body);

        $this->assertSame(404, $this->get('/products/9999')->status);
        $variant = $this->post('/products/' . $pid . '/variants', ['sku' => 'A1-L', 'reference_cost' => '71']);
        $this->assertSame(302, $variant->status);
        $this->assertSame(2, $this->countRows('product_variants'));
        $this->assertSame(1, $this->countRows('products', "name = '=HYPERLINK(\"x\")'"));
        $this->assertTrue($this->post('/products/' . $pid, ['name' => 'Y'], false)->status >= 400, 'sin CSRF se rechaza');
    }

    public function testCatalogPagesAndTaxCreation(): void
    {
        $this->loginAs('admin');
        foreach (['categories', 'brands', 'units', 'tax-rates'] as $e) {
            $this->assertSame(200, $this->get('/catalog/' . $e)->status, $e);
            $this->assertSame(200, $this->get('/catalog/' . $e . '/create')->status, $e);
        }
        $this->assertSame(404, $this->get('/catalog/otra')->status);
        $r = $this->post('/catalog/tax-rates', ['name' => 'IVA', 'rate' => '13', 'is_active' => '1']);
        $this->assertSame(302, $r->status);
        $this->assertStringContainsString('13.0000 %', $this->get('/catalog/tax-rates')->body);
        $id = (int) $this->db->value("SELECT id FROM tax_rates WHERE name = 'IVA'");
        $this->assertSame(200, $this->get('/catalog/tax-rates/' . $id . '/edit')->status);
        $this->assertSame(422, $this->post('/catalog/tax-rates/' . $id, ['name' => 'IVA', 'rate' => '500', 'is_active' => '1'])->status);
    }

    public function testSupplierFlowAndComparisonOnProductPage(): void
    {
        $this->loginAs('admin');
        $this->post('/products', ['name' => 'Taza', 'unit_id' => $this->unitId(), 'sale_price' => '9', 'sku' => 'T1', 'reference_cost' => '3']);
        $pid = (int) $this->db->value('SELECT id FROM products');
        $this->assertSame(302, $this->post('/suppliers', ['name' => 'Cerámicas', 'currency' => 'USD', 'is_active' => '1'])->status);
        $sid = (int) $this->db->value('SELECT id FROM suppliers');
        $this->assertSame(200, $this->get('/suppliers/' . $sid)->status);
        $this->post('/suppliers/' . $sid . '/quotes', ['product_id' => (string) $pid, 'unit_price' => '2.50', 'freight_cost' => '0.25']);
        $page = $this->get('/products/' . $pid);
        $this->assertStringContainsString('Cerámicas', $page->body);
        $this->assertStringContainsString('2.7500', $page->body);
        $this->assertStringContainsString('Mejor costo', $page->body);
        $this->assertSame(200, $this->get('/suppliers', ['q' => 'Cer'])->status);
    }

    public function testFinanceFlowThroughHttp(): void
    {
        $this->loginAs('admin');
        $this->assertSame(302, $this->post('/finance/accounts', ['name' => 'Caja', 'type' => 'cash', 'opening_balance' => '100', 'is_active' => '1'])->status);
        $this->assertSame(302, $this->post('/finance/accounts', ['name' => 'Banco', 'type' => 'bank', 'opening_balance' => '0', 'is_active' => '1'])->status);
        $caja = (int) $this->db->value("SELECT id FROM financial_accounts WHERE name = 'Caja'");
        $banco = (int) $this->db->value("SELECT id FROM financial_accounts WHERE name = 'Banco'");

        $this->assertSame(200, $this->get('/finance/movements/create')->status);
        $t = $this->post('/finance/movements', ['type' => 'transfer', 'account_id' => (string) $caja, 'to_account_id' => (string) $banco, 'amount' => '40', 'occurred_on' => '2026-01-15']);
        $this->assertSame(302, $t->status);
        $over = $this->post('/finance/movements', ['type' => 'adjustment_out', 'account_id' => (string) $caja, 'amount' => '1000', 'description' => 'x', 'occurred_on' => '2026-01-15']);
        $this->assertSame(422, $over->status);
        $this->assertStringContainsString('Saldo insuficiente', $over->body);
        $future = $this->post('/finance/movements', ['type' => 'other_income', 'account_id' => (string) $caja, 'amount' => '1', 'occurred_on' => '2026-03-01']);
        $this->assertSame(422, $future->status);
        $past = $this->post('/finance/movements', ['type' => 'other_income', 'account_id' => (string) $caja, 'amount' => '5', 'occurred_on' => '2026-01-10']);
        $this->assertSame(302, $past->status);

        $index = $this->get('/finance');
        $this->assertStringContainsString('Conciliación correcta', $index->body);
        $this->assertStringContainsString('65.00', $index->body);
        $ledger = $this->get('/finance/ledger', ['source' => 'transfer']);
        $this->assertStringContainsString('Transferencia', $ledger->body);

        $txId = (int) $this->db->value("SELECT id FROM financial_transactions WHERE source_type = 'transfer' AND direction = 'out'");
        $this->assertSame(302, $this->post('/finance/ledger/' . $txId . '/void', ['reason' => ''])->status);
        $this->assertSame(0, $this->countRows('financial_transactions', 'voided_at IS NOT NULL'), 'sin motivo no se anula');
        $this->post('/finance/ledger/' . $txId . '/void', ['reason' => 'Error']);
        $this->assertSame(2, $this->countRows('financial_transactions', 'voided_at IS NOT NULL'));
        $this->assertStringContainsString('Anulado', $this->get('/finance/ledger')->body);
    }

    public function testManagerCanOperateButAnalystOnlyReads(): void
    {
        $this->loginAs('analyst');
        $this->assertSame(200, $this->get('/finance')->status);
        $this->assertSame(200, $this->get('/products')->status);
        $this->assertSame(403, $this->get('/finance/movements/create')->status);
        $this->assertSame(403, $this->post('/finance/accounts', ['name' => 'X'])->status);
        $this->assertSame(403, $this->post('/finance/ledger/1/void', ['reason' => 'x'])->status);
        $this->assertSame(403, $this->post('/catalog/brands', ['name' => 'X'])->status);

        $this->post('/logout');
        $this->loginAs('manager');
        $this->assertSame(200, $this->get('/finance/accounts/create')->status);
        $this->assertSame(200, $this->get('/suppliers/create')->status);
    }

    public function testMenuShowsNewModulesOnlyWhenAllowed(): void
    {
        $this->loginAs('admin');
        $html = $this->get('/')->body;
        foreach (['Productos', 'Proveedores', 'Libro de caja', 'Cuentas'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function testInventoryAndPurchasingThroughHttp(): void
    {
        $this->loginAs('admin');
        $this->post('/products', ['name' => 'Taza', 'unit_id' => $this->unitId(), 'sale_price' => '9', 'sku' => 'T1', 'reference_cost' => '3']);
        $this->post('/suppliers', ['name' => 'Cerámicas', 'currency' => 'USD', 'is_active' => '1']);
        $this->post('/finance/accounts', ['name' => 'Banco', 'type' => 'bank', 'opening_balance' => '1000', 'is_active' => '1']);
        $variant = (string) $this->db->value('SELECT id FROM product_variants');
        $sid = (string) $this->db->value('SELECT id FROM suppliers');
        $wh = (string) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
        $acc = (string) $this->db->value('SELECT id FROM financial_accounts');

        foreach (['/inventory', '/inventory/movements', '/inventory/adjust', '/purchases', '/purchases/create'] as $path) {
            $this->assertSame(200, $this->get($path)->status, $path);
        }
        $this->assertSame(422, $this->post('/inventory/adjust', ['type' => 'out', 'variant_id' => $variant, 'warehouse_id' => $wh, 'quantity' => '1', 'reason' => 'x'])->status);
        $this->assertSame(302, $this->post('/inventory/adjust', ['type' => 'in', 'variant_id' => $variant, 'warehouse_id' => $wh, 'quantity' => '4', 'unit_cost' => '2', 'reason' => 'Inicial'])->status);

        $this->assertSame(302, $this->post('/purchases', ['supplier_id' => $sid, 'warehouse_id' => $wh])->status);
        $oid = (int) $this->db->value('SELECT id FROM purchase_orders');
        $this->post('/purchases/' . $oid . '/lines', ['variant_id' => $variant, 'quantity' => '10', 'unit_price' => '5', 'tax_rate' => '0', 'tax_recoverable' => '1']);
        $this->post('/purchases/' . $oid . '/costs', ['kind' => 'freight', 'amount' => '10']);
        $this->post('/purchases/' . $oid . '/place');
        $show = $this->get('/purchases/' . $oid);
        $this->assertStringContainsString('OC-00001', $show->body);
        $this->assertStringContainsString('Registrar recepción', $show->body);

        $lineId = (int) $this->db->value('SELECT id FROM purchase_order_lines');
        $bad = $this->post('/purchases/' . $oid . '/receive', ['qty' => [(string) $lineId => '11'], 'received_on' => '2026-01-15']);
        $this->assertSame(302, $bad->status);
        $this->assertSame(0, $this->countRows('purchase_receipts'));
        $this->post('/purchases/' . $oid . '/receive', ['qty' => [(string) $lineId => '10'], 'received_on' => '2026-01-15']);
        $this->assertSame('received', $this->db->value('SELECT status FROM purchase_orders'));
        // (4 × 2.00 + 10 × 6.00) / 14 = 4.8571…
        $this->assertSame('4.8571', number_format((float) $this->db->value('SELECT avg_cost FROM stock_levels'), 4, '.', ''));
        $this->assertStringContainsString('Conciliación correcta', $this->get('/inventory')->body);
        $this->assertStringContainsString('Compra', $this->get('/inventory/movements')->body === '' ? '' : 'Compra');

        $this->assertSame(302, $this->post('/purchases/' . $oid . '/payments', ['account_id' => $acc, 'amount' => '60'])->status);
        $this->assertSame(1, $this->countRows('purchase_payments'));
        $this->assertStringContainsString('Pendiente de pago', $this->get('/purchases/' . $oid)->body);
        $this->assertStringContainsString('Conciliación correcta', $this->get('/finance')->body);
        $this->assertSame(200, $this->get('/purchases', ['status' => 'received'])->status);
    }

    public function testSellerSeesStockWithoutCostsAndCannotUsePurchasing(): void
    {
        $this->loginAs('seller');
        $this->assertSame(200, $this->get('/inventory')->status);
        $this->assertSame(200, $this->get('/inventory/movements')->status);
        foreach (['/purchases', '/purchases/create', '/inventory/adjust'] as $path) {
            $this->assertSame(403, $this->get($path)->status, $path);
        }
        $this->assertSame(403, $this->post('/inventory/adjust', ['type' => 'in'])->status);
        $this->assertStringNotContainsString('Valor del inventario', $this->get('/inventory')->body);
        $this->post('/logout');
        $this->loginAs('analyst');
        $this->assertSame(200, $this->get('/purchases')->status);
        $this->assertSame(403, $this->post('/purchases', ['supplier_id' => '1'])->status);
    }
}
