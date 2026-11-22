<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Export\PdfWriter;
use Bic\Core\Export\Table;
use Bic\Core\Export\XlsxWriter;
use Bic\Tests\Support\AppTestCase;

final class ReportsInsightsTest extends AppTestCase
{
    private function table(): Table
    {
        return new Table('Ventas & <más>', 'Sub', [['key' => 'n', 'label' => 'Nombre'], ['key' => 'm', 'label' => 'Importe', 'type' => 'money']],
            [['n' => '=CMD()', 'm' => '1234.5'], ['n' => 'Ñandú (café)', 'm' => '2.25']], ['n' => 'Total', 'm' => '1236.75']);
    }

    public function testXlsxIsValidZipWithRealNumbersAndInlineText(): void
    {
        if (!XlsxWriter::available()) {
            $this->markTestSkipped('ext-zip no disponible');
        }
        $bin = XlsxWriter::render($this->table());
        $tmp = tempnam(sys_get_temp_dir(), 't');
        file_put_contents($tmp, $bin);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<v>1234.5</v>', $sheet);
        $this->assertStringContainsString('inlineStr', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet, 'nunca fórmulas');
        $this->assertStringContainsString('Ventas &amp; &lt;más&gt;', (string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($tmp);
        $this->assertSame(1, XlsxWriter::render(new Table('x', 'y', [['key' => 'a', 'label' => 'A']], []))[0] === 'P' ? 1 : 0, 'un zip empieza por PK');
    }

    public function testPdfStructure(): void
    {
        $rows = [];
        for ($i = 0; $i < 150; $i++) {
            $rows[] = ['n' => "Fila $i", 'm' => (string) $i];
        }
        $pdf = PdfWriter::render(new Table('T', 'S', [['key' => 'n', 'label' => 'N'], ['key' => 'm', 'label' => 'M', 'type' => 'money']], $rows), 'pie');
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertSame('%%EOF', substr($pdf, -5));
        $this->assertTrue(preg_match('/\/Count (\d+)/', $pdf, $m) === 1 && (int) $m[1] >= 3, 'varias páginas');
        preg_match('/startxref\n(\d+)/', $pdf, $x);
        $this->assertSame('xref', substr($pdf, (int) $x[1], 4), 'la tabla xref apunta bien');
        $this->assertStringStartsWith('%PDF', PdfWriter::render(new Table('Vacío', 'S', [['key' => 'n', 'label' => 'N']], [])));
    }

    public function testCsvExportIsInjectionSafe(): void
    {
        $r = \Bic\Core\Export\Exporter::response($this->table(), 'csv', 'x');
        $this->assertStringContainsString("'=CMD()", $r->body);
        $this->assertStringContainsString('Total', $r->body);
    }

    private function sell(): void
    {
        $unit = (string) $this->db->value("SELECT id FROM units WHERE code = 'un'");
        $this->post('/products', ['name' => 'Gorra', 'unit_id' => $unit, 'sale_price' => '15', 'sku' => 'G1', 'reference_cost' => '6', 'min_stock' => '10']);
        $this->post('/products', ['name' => 'Bufanda', 'unit_id' => $unit, 'sale_price' => '5', 'sku' => 'B1', 'reference_cost' => '8']);
        $wh = (string) $this->db->value('SELECT id FROM warehouses WHERE is_default = 1');
        $g = (string) $this->db->value("SELECT id FROM product_variants WHERE sku = 'G1'");
        $b = (string) $this->db->value("SELECT id FROM product_variants WHERE sku = 'B1'");
        $this->post('/inventory/adjust', ['type' => 'in', 'warehouse_id' => $wh, 'variant_id' => $g, 'quantity' => '10', 'unit_cost' => '6', 'reason' => 'x']);
        $this->post('/inventory/adjust', ['type' => 'in', 'warehouse_id' => $wh, 'variant_id' => $b, 'quantity' => '3', 'unit_cost' => '8', 'reason' => 'x']);
        $this->post('/customers', ['name' => 'Ana Pérez']);
        $this->post('/sales', ['warehouse_id' => $wh, 'customer_id' => (string) $this->db->value('SELECT id FROM customers')]);
        $oid = (int) $this->db->value('SELECT id FROM orders');
        $this->post("/sales/{$oid}/lines", ['variant_id' => $g, 'quantity' => '4']);
        $this->post("/sales/{$oid}/submit");
        $this->post("/sales/{$oid}/deliver");
    }

    public function testReportsScreenAndExports(): void
    {
        $this->loginAs('admin');
        $this->sell();
        $this->assertSame(200, $this->get('/reports')->status);
        $page = $this->get('/reports/sales');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('PE-', $page->body);
        $this->assertStringContainsString('60.00', $page->body, '4 × 15');
        $p = $this->get('/reports/products');
        $this->assertStringContainsString('Gorra', $p->body);
        $this->assertStringContainsString('36.00', $p->body, 'utilidad 60 − 24');
        $this->assertStringContainsString('60.00', $this->get('/reports/pnl')->body);
        $this->assertStringContainsString('Gorra', $this->get('/reports/inventory')->body);
        $this->assertSame(200, $this->get('/reports/receivables')->status);
        $this->assertSame(200, $this->get('/reports/expenses')->status);
        $this->assertSame(404, $this->get('/reports/nope')->status);

        $csv = $this->get('/reports/sales', ['format' => 'csv']);
        $this->assertStringContainsString('text/csv', $csv->headers['Content-Type']);
        $this->assertStringContainsString('60.00', $csv->body);
        $pdf = $this->get('/reports/sales', ['format' => 'pdf']);
        $this->assertStringStartsWith('%PDF', $pdf->body);
        if (XlsxWriter::available()) {
            $this->assertStringStartsWith('PK', $this->get('/reports/products', ['format' => 'xlsx'])->body);
        }
        $this->assertSame(1, $this->countRows('audit_logs', "action = 'report.export'") >= 2 ? 1 : 0, 'las exportaciones se auditan');
    }

    public function testReportPermissions(): void
    {
        $this->loginAs('seller');
        $this->assertSame(403, $this->get('/reports')->status);
        $this->assertSame(403, $this->get('/insights')->status);
        $this->post('/logout');
        $this->loginAs('analyst');
        $this->assertSame(200, $this->get('/reports/sales', ['format' => 'csv'])->status, 'el analista exporta');
        $this->assertSame(200, $this->get('/insights')->status);
    }

    public function testInsightsAbcReorderSlowAndBelowCost(): void
    {
        $this->loginAs('admin');
        $this->sell();
        $page = $this->get('/insights');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Clasificación ABC', $page->body);
        $this->assertStringContainsString('Reposición sugerida', $page->body);
        $this->assertStringContainsString('Gorra', $page->body, 'vendida y bajo el mínimo (6 < 10)');
        $this->assertStringContainsString('Bufanda', $page->body, 'estancada y con precio bajo costo');
        $this->assertStringContainsString('Ana Pérez', $page->body);
        $this->assertSame(200, $this->get('/insights', ['days' => '30'])->status);
        $this->assertSame(200, $this->get('/insights', ['days' => 'x'])->status);
    }
}
