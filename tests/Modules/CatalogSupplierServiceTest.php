<?php

declare(strict_types=1);

namespace Bic\Tests\Modules;

use Bic\Core\Audit\AuditLogger;
use Bic\Modules\Catalog\CatalogService;
use Bic\Modules\Products\ProductRepository;
use Bic\Modules\Products\ProductService;
use Bic\Modules\Suppliers\SupplierRepository;
use Bic\Modules\Suppliers\SupplierService;
use Bic\Tests\Support\DatabaseTestCase;

final class CatalogSupplierServiceTest extends DatabaseTestCase
{
    private function catalog(): CatalogService
    {
        return new CatalogService($this->db, new AuditLogger($this->db, $this->clock));
    }

    private function products(): ProductService
    {
        return new ProductService($this->db, new ProductRepository($this->db), new AuditLogger($this->db, $this->clock));
    }

    private function suppliers(): SupplierService
    {
        return new SupplierService($this->db, new SupplierRepository($this->db), new AuditLogger($this->db, $this->clock));
    }

    private function actor(): array
    {
        return $this->actorFor($this->makeUser('admin'));
    }

    private function unitId(): int
    {
        return (int) $this->db->value("SELECT id FROM units WHERE code = 'un'");
    }

    /** @return array<string,mixed> */
    private function productInput(array $o = []): array
    {
        return array_merge(['name' => 'Camiseta', 'unit_id' => (string) $this->unitId(), 'sale_price' => '25.00', 'sku' => 'CAM-001', 'reference_cost' => '10', 'status' => 'active'], $o);
    }

    public function testSeedsProvideUnitsAndOnlyAnExemptDefaultTax(): void
    {
        $this->assertSame(1, $this->countRows('tax_rates'));
        $row = $this->db->first('SELECT name, rate, is_default FROM tax_rates');
        $this->assertSame('Exento', $row['name']);
        $this->assertSame(1, (int) $row['is_default']);
        $this->assertGreaterThan(3, $this->countRows('units'));
    }

    public function testTaxRateRulesAndSingleDefault(): void
    {
        $actor = $this->actor();
        $this->assertArrayHasKey('rate', $this->catalog()->save($actor, 'tax-rates', null, ['name' => 'X', 'rate' => '150'])['errors']);
        $this->assertArrayHasKey('rate', $this->catalog()->save($actor, 'tax-rates', null, ['name' => 'X', 'rate' => 'abc'])['errors']);
        $r = $this->catalog()->save($actor, 'tax-rates', null, ['name' => 'General', 'rate' => '13', 'is_active' => 1, 'is_default' => 1]);
        $this->assertTrue($r['ok']);
        $this->assertSame(1, $this->countRows('tax_rates', 'is_default = 1'));
        $this->assertSame(1, $this->countRows('tax_rates', 'is_default = 1 AND id = ?', [$r['id']]));

        $exento = (int) $this->db->value("SELECT id FROM tax_rates WHERE name = 'Exento'");
        $again = $this->catalog()->save($actor, 'tax-rates', $r['id'], ['name' => 'General', 'rate' => '13', 'is_active' => 1]);
        $this->assertArrayHasKey('is_default', $again['errors'], 'no se puede quitar el predeterminado sin elegir otro');
        $this->assertTrue($this->catalog()->save($actor, 'tax-rates', $exento, ['name' => 'Exento', 'rate' => '0', 'is_active' => 1, 'is_default' => 1])['ok']);
        $this->assertSame($exento, (int) $this->db->value('SELECT id FROM tax_rates WHERE is_default = 1'));
        $this->assertArrayHasKey('name', $this->catalog()->save($actor, 'tax-rates', null, ['name' => 'general', 'rate' => '5', 'is_active' => 1])['errors'], 'nombre único sin distinguir mayúsculas');
    }

    public function testCategoryAndUnitValidation(): void
    {
        $actor = $this->actor();
        $this->assertTrue($this->catalog()->save($actor, 'categories', null, ['name' => 'Ropa', 'is_active' => 1])['ok']);
        $this->assertArrayHasKey('name', $this->catalog()->save($actor, 'categories', null, ['name' => 'ROPA', 'is_active' => 1])['errors']);
        $this->assertArrayHasKey('code', $this->catalog()->save($actor, 'units', null, ['code' => 'un', 'name' => 'Otra', 'is_active' => 1])['errors']);
        $this->assertArrayHasKey('code', $this->catalog()->save($actor, 'units', null, ['code' => 'no válido!', 'name' => 'X'])['errors']);
        $this->assertSame(404, $this->catalog()->save($actor, 'brands', 999, ['name' => 'X'])['status']);
        $this->assertSame(1, $this->countRows('audit_logs', "action = 'catalog.categories.create'"));
    }

    public function testCreateProductBuildsVariantHistoryAndUsesDefaultTax(): void
    {
        $actor = $this->actor();
        $r = $this->products()->create($actor, $this->productInput(['promo_price' => '20']));
        $this->assertTrue($r['ok']);
        $p = $this->db->first('SELECT * FROM products WHERE id = ?', [$r['id']]);
        $this->assertSame((int) $this->db->value("SELECT id FROM tax_rates WHERE is_default = 1"), (int) $p['tax_rate_id'], 'sin impuesto elegido, toma el predeterminado');
        $v = $this->db->first('SELECT * FROM product_variants WHERE product_id = ?', [$r['id']]);
        $this->assertSame(1, (int) $v['is_default']);
        $this->assertSame('CAM-001', $v['sku']);
        $this->assertSame(3, $this->countRows('product_price_history', 'product_id = ?', [$r['id']]));
        $this->assertSame(1, $this->countRows('audit_logs', "action = 'product.create'"));
    }

    public function testProductValidationAndUniqueness(): void
    {
        $actor = $this->actor();
        $this->products()->create($actor, $this->productInput(['barcode' => '7501']));
        $e = $this->products()->create($actor, $this->productInput(['sku' => 'cam-001', 'barcode' => '7501', 'name' => '']))['errors'];
        $this->assertSame(['name', 'sku', 'barcode'], array_keys($e));
        $e = $this->products()->create($actor, $this->productInput(['sku' => 'B', 'sale_price' => '-1', 'promo_price' => '3', 'unit_id' => '', 'reference_cost' => 'x', 'min_stock' => '-2', 'status' => 'raro']))['errors'];
        foreach (['sale_price', 'unit_id', 'reference_cost', 'min_stock', 'status'] as $k) {
            $this->assertArrayHasKey($k, $e);
        }
        $e = $this->products()->create($actor, $this->productInput(['sku' => 'C', 'promo_price' => '30']))['errors'];
        $this->assertArrayHasKey('promo_price', $e);
        $this->assertArrayHasKey('unit_id', $this->products()->create($actor, $this->productInput(['sku' => 'D', 'unit_id' => '9999']))['errors']);
        $this->assertSame(1, $this->countRows('products'), 'ninguna creación inválida deja datos a medias');
        $this->assertSame(1, $this->countRows('product_variants'));
    }

    public function testPriceChangesAreRecordedOnlyWhenTheyChange(): void
    {
        $actor = $this->actor();
        $id = $this->products()->create($actor, $this->productInput())['id'];
        $base = $this->countRows('product_price_history');
        $taxId = (string) $this->db->value('SELECT tax_rate_id FROM products WHERE id = ?', [$id]);
        $same = $this->productInput(['sale_price' => '25.0', 'tax_rate_id' => $taxId]);
        $this->assertTrue($this->products()->update($actor, $id, $same)['ok']);
        $this->assertSame($base, $this->countRows('product_price_history'), 'mismo precio con otro formato: sin historial');

        $this->assertTrue($this->products()->update($actor, $id, $this->productInput(['sale_price' => '27.50', 'tax_rate_id' => $taxId]))['ok']);
        $h = $this->db->first("SELECT * FROM product_price_history WHERE kind = 'sale_price' ORDER BY id DESC");
        $this->assertSame(['25', '27.5'], [rtrim(rtrim((string) $h['old_price'], '0'), '.'), rtrim(rtrim((string) $h['new_price'], '0'), '.')]);
        $this->assertSame(404, $this->products()->update($actor, 999, $this->productInput(['tax_rate_id' => $taxId]))['status']);
    }

    public function testVariantRules(): void
    {
        $actor = $this->actor();
        $id = $this->products()->create($actor, $this->productInput())['id'];
        $default = (int) $this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$id]);

        $new = $this->products()->saveVariant($actor, $id, null, ['sku' => 'CAM-001-M', 'variant_name' => 'Talla M', 'reference_cost' => '11.5']);
        $this->assertTrue($new['ok']);
        $this->assertArrayHasKey('sku', $this->products()->saveVariant($actor, $id, null, ['sku' => 'CAM-001-M'])['errors']);
        $this->assertArrayHasKey('is_active', $this->products()->saveVariant($actor, $id, $default, ['sku' => 'CAM-001', 'reference_cost' => '10'])['errors'], 'la principal no se desactiva');

        $before = $this->countRows('product_price_history', "kind = 'reference_cost'");
        $this->assertTrue($this->products()->saveVariant($actor, $id, $new['id'], ['sku' => 'CAM-001-M', 'reference_cost' => '12', 'is_active' => 1])['ok']);
        $this->assertSame($before + 1, $this->countRows('product_price_history', "kind = 'reference_cost'"));
        $this->assertSame(404, $this->products()->saveVariant($actor, $id, 9999, ['sku' => 'Z'])['status']);
    }

    public function testSupplierValidation(): void
    {
        $actor = $this->actor();
        $this->assertTrue($this->suppliers()->save($actor, null, ['name' => 'Textiles SA', 'email' => 'v@t.com', 'currency' => 'eur', 'lead_time_days' => '15', 'is_active' => 1])['ok']);
        $this->assertSame('EUR', $this->db->value("SELECT currency FROM suppliers WHERE name = 'Textiles SA'"));
        $e = $this->suppliers()->save($actor, null, ['name' => 'textiles sa', 'email' => 'mal', 'currency' => 'EURO', 'lead_time_days' => 'x'])['errors'];
        $this->assertSame(['name', 'email', 'currency', 'lead_time_days'], array_keys($e));
    }

    public function testQuotesAndLandedCostComparison(): void
    {
        $actor = $this->actor();
        $pid = $this->products()->create($actor, $this->productInput())['id'];
        $local = $this->suppliers()->save($actor, null, ['name' => 'Local', 'currency' => 'USD', 'is_active' => 1])['id'];
        $import = $this->suppliers()->save($actor, null, ['name' => 'Importador', 'currency' => 'EUR', 'is_active' => 1])['id'];
        $old = $this->suppliers()->save($actor, null, ['name' => 'Vencido', 'currency' => 'USD', 'is_active' => 1])['id'];
        $today = '2026-01-15';

        $this->assertTrue($this->suppliers()->addQuote($actor, $local, ['product_id' => $pid, 'unit_price' => '9.00', 'freight_cost' => '1.50', 'other_cost' => '0.50'], $today)['ok']);
        // 8 EUR + 0.50 flete = 8.50 EUR × 1.10 = 9.35 USD  → menor que 11.00 del local
        $this->assertTrue($this->suppliers()->addQuote($actor, $import, ['product_id' => $pid, 'currency' => 'EUR', 'exchange_rate' => '1.10', 'unit_price' => '8', 'freight_cost' => '0.5'], $today)['ok']);
        $this->assertTrue($this->suppliers()->addQuote($actor, $old, ['product_id' => $pid, 'unit_price' => '5', 'quoted_on' => '2025-12-01', 'valid_until' => '2025-12-31'], $today)['ok']);

        $rows = (new SupplierRepository($this->db))->compare($this->businessId(), $pid, $today);
        $this->assertSame(['Vencido', 'Importador', 'Local'], array_column($rows, 'supplier_name'));
        $this->assertSame(['5.0000', '9.3500', '11.0000'], array_column($rows, 'landed_base'));
        $this->assertSame('Importador', array_values(array_filter($rows, static fn (array $r): bool => !empty($r['best'])))[0]['supplier_name'], 'la mejor vigente ignora la vencida');

        // Una cotización nueva sustituye a la anterior en la comparación; el historial se conserva.
        $this->suppliers()->addQuote($actor, $local, ['product_id' => $pid, 'unit_price' => '7', 'quoted_on' => '2026-01-15'], $today);
        $this->assertSame(4, $this->countRows('supplier_prices'));
        $this->assertSame(1, $this->countRows('product_suppliers', 'supplier_id = ?', [$local]));
        $rows = (new SupplierRepository($this->db))->compare($this->businessId(), $pid, $today);
        $this->assertSame('7.0000', array_values(array_filter($rows, static fn (array $r): bool => $r['supplier_name'] === 'Local'))[0]['landed_base']);
    }

    public function testQuoteValidation(): void
    {
        $actor = $this->actor();
        $pid = $this->products()->create($actor, $this->productInput())['id'];
        $s = $this->suppliers()->save($actor, null, ['name' => 'P', 'is_active' => 1])['id'];
        $e = $this->suppliers()->addQuote($actor, $s, ['product_id' => 999, 'currency' => 'EUR', 'unit_price' => '-1', 'min_quantity' => '0', 'quoted_on' => '2030-01-01'], '2026-01-15')['errors'];
        foreach (['product_id', 'exchange_rate', 'unit_price', 'min_quantity', 'quoted_on'] as $k) {
            $this->assertArrayHasKey($k, $e);
        }
        $e = $this->suppliers()->addQuote($actor, $s, ['product_id' => $pid, 'unit_price' => '1', 'quoted_on' => '2026-01-10', 'valid_until' => '2026-01-01'], '2026-01-15')['errors'];
        $this->assertArrayHasKey('valid_until', $e);
        $this->assertSame(0, $this->countRows('supplier_prices'));
        $this->assertSame(404, $this->suppliers()->addQuote($actor, 999, ['product_id' => $pid, 'unit_price' => '1'], '2026-01-15')['status']);
    }
}
