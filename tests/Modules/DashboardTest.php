<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Charts;
use Bic\Tests\Support\AppTestCase;

final class DashboardTest extends AppTestCase
{
    public function testChartsEscapeAndHandleEmptyAndZero(): void
    {
        $this->assertSame('', Charts::bars([], [], 'x'));
        $svg = Charts::bars(['<b>', 'c'], [0.0, 5.0], '"quote"', ['<i>', '5.00']);
        $this->assertStringNotContainsString('<b>', $svg);
        $this->assertStringContainsString('&lt;b&gt;', $svg);
        $this->assertStringContainsString('&quot;quote&quot;', $svg);
        $this->assertStringNotContainsString('style=', $svg, 'compatible con la CSP');
        $this->assertStringContainsString('<rect', Charts::hbars(['A'], [0.0], 'r'), 'valores en cero no rompen');
        $this->assertStringContainsString('…', Charts::hbars([str_repeat('x', 60)], [1.0], 'r'));
    }

    public function testAdminSeesRealIndicatorsAndSellerOnlyHisBlocks(): void
    {
        $this->loginAs('admin');
        $unit = (string) $this->db->value("SELECT id FROM units WHERE code = 'un'");
        $this->post('/products', ['name' => 'Gorra', 'unit_id' => $unit, 'sale_price' => '15', 'sku' => 'G1', 'reference_cost' => '6', 'min_stock' => '5']);
        $v = (string) $this->db->value('SELECT id FROM product_variants');
        $wh = (string) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
        $this->post('/inventory/adjust', ['type' => 'in', 'warehouse_id' => $wh, 'variant_id' => $v, 'quantity' => '4', 'unit_cost' => '6', 'reason' => 'x']);
        $this->post('/sales', ['warehouse_id' => $wh]);
        $oid = (int) $this->db->value('SELECT id FROM orders');
        $this->post("/sales/{$oid}/lines", ['variant_id' => $v, 'quantity' => '2']);
        $this->post("/sales/{$oid}/submit");
        $this->post("/sales/{$oid}/deliver");

        $page = $this->get('/');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('<svg class="chart"', $page->body);
        $this->assertStringContainsString('30.00', $page->body, 'ventas netas del día');
        $this->assertStringContainsString('Stock bajo', $page->body);
        $this->assertStringContainsString('Gorra', $page->body);
        $this->assertStringContainsString('Por cobrar', $page->body);
        $this->assertSame(200, $this->get('/', ['days' => '7'])->status);
        $this->assertSame(200, $this->get('/', ['days' => 'zzz'])->status, 'período inválido usa el valor por defecto');
        $this->post('/logout');

        $this->loginAs('seller');
        $s = $this->get('/');
        $this->assertSame(200, $s->status);
        $this->assertStringContainsString('Ventas por día', $s->body);
        $this->assertStringNotContainsString('Ganancia neta', $s->body);
        $this->assertStringNotContainsString('Dinero en cuentas', $s->body);
        $this->assertStringNotContainsString('Valor del inventario', $s->body);
        $this->assertStringNotContainsString('Por pagar', $s->body);
    }
}
