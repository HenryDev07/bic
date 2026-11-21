<?php

declare(strict_types=1);

namespace Bic\Modules\Products;

use Bic\Core\Controller;
use Bic\Core\Csv;
use Bic\Core\Http\Request;
use Bic\Core\Http\Response;
use Bic\Core\Money\Decimal;
use Bic\Core\Money\PriceCalculator;
use Bic\Core\Money\TaxCalculator;
use Bic\Core\Paginator;
use Bic\Modules\Suppliers\SupplierRepository;
use InvalidArgumentException;

final class ProductsController extends Controller
{
    private function repo(): ProductRepository
    {
        return new ProductRepository($this->app->db);
    }

    private function service(): ProductService
    {
        return new ProductService($this->app->db, $this->repo(), $this->app->auditLogger);
    }

    /** @return array{0:string,1:string,2:int} */
    private function filters(Request $request): array
    {
        return [(string) $request->string('q', ''), (string) $request->string('status', ''), (int) $request->string('category_id', '0')];
    }

    public function index(Request $request): Response
    {
        $biz = $this->app->actor()['business_id'];
        [$q, $status, $cat] = $this->filters($request);
        $total = $this->repo()->paginate($biz, $q, $status, $cat, 1, 0)['total'];
        $pager = Paginator::make($total, $this->page($request));
        $result = $this->repo()->paginate($biz, $q, $status, $cat, $pager['per_page'], $pager['offset']);

        return $this->render('products::index', [
            'title' => 'Productos', 'rows' => $result['rows'], 'q' => $q, 'status' => $status, 'categoryId' => $cat,
            'options' => $this->repo()->options($biz), 'statuses' => ProductService::STATUSES, 'showCost' => $this->app->can('products.cost'),
            'pager' => $pager, 'pagePath' => '/products', 'pageQuery' => ['q' => $q, 'status' => $status, 'category_id' => $cat ?: ''],
        ]);
    }

    public function export(Request $request): Response
    {
        $biz = $this->app->actor()['business_id'];
        [$q, $status, $cat] = $this->filters($request);
        $cost = $this->app->can('products.cost');
        $header = ['SKU', 'Código de barras', 'Producto', 'Categoría', 'Marca', 'Unidad', 'Impuesto', 'Tasa %', 'Precio incluye impuesto', 'Precio de venta', 'Precio promoción', 'Estado'];
        if ($cost) {
            $header[] = 'Costo de referencia';
        }
        $rows = [];
        foreach ($this->repo()->export($biz, $q, $status, $cat) as $r) {
            $row = [$r['sku'], $r['barcode'], $r['name'], $r['category_name'], $r['brand_name'], $r['unit_code'], $r['tax_name'],
                Decimal::from($r['tax_rate'], 4)->toString(), (int) $r['price_includes_tax'] === 1 ? 'Sí' : 'No',
                Decimal::from($r['sale_price'], 2)->toString(), $r['promo_price'] === null ? '' : Decimal::from($r['promo_price'], 2)->toString(),
                ProductService::STATUSES[$r['status']] ?? $r['status']];
            if ($cost) {
                $row[] = Decimal::from($r['reference_cost'], 4)->toString();
            }
            $rows[] = $row;
        }
        $this->app->audit('product.export', 'products', null, null, ['rows' => count($rows)]);

        return Csv::response('productos-' . $this->app->clock->now()->format('Ymd') . '.csv', $header, $rows);
    }

    public function create(): Response
    {
        $biz = $this->app->actor()['business_id'];

        return $this->form('create', null, ['status' => 'active', 'sale_price' => '0', 'min_stock' => '0', 'tax_rate_id' => (string) ($this->repo()->defaultTaxId($biz) ?? '')], []);
    }

    public function store(Request $request): Response
    {
        $input = $this->input($request);
        $result = $this->service()->create($this->app->actor(), $input);
        if (!$result['ok']) {
            return $this->form('create', null, $input, $result['errors'], 422);
        }
        $this->flash('success', 'Producto creado.');

        return $this->redirect('/products/' . $result['id']);
    }

    public function show(Request $request, array $params): Response
    {
        $biz = $this->app->actor()['business_id'];
        $id = $this->id($params);
        $product = $this->repo()->find($biz, $id) ?? $this->notFound();
        $showCost = $this->app->can('products.cost');

        $calc = null;
        $suppliers = [];
        if ($showCost) {
            $calc = $this->calculator($product, $request);
            if ($this->app->can('suppliers.view')) {
                $suppliers = (new SupplierRepository($this->app->db))->compare($biz, $id, $this->app->clock->now()->format('Y-m-d'));
            }
        }

        return $this->render('products::show', [
            'title' => (string) $product['name'], 'p' => $product, 'variants' => $this->repo()->variants($biz, $id),
            'history' => $this->repo()->history($biz, $id), 'statuses' => ProductService::STATUSES,
            'showCost' => $showCost, 'calc' => $calc, 'suppliers' => $suppliers, 'errors' => [], 'values' => [],
        ]);
    }

    public function edit(Request $request, array $params): Response
    {
        $biz = $this->app->actor()['business_id'];
        $product = $this->repo()->find($biz, $this->id($params)) ?? $this->notFound();

        return $this->form('edit', $product, $product, []);
    }

    public function update(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $input = $this->input($request);
        $result = $this->service()->update($this->app->actor(), $id, $input);
        if ($result['status'] === 404) {
            $this->notFound();
        }
        if (!$result['ok']) {
            return $this->form('edit', $this->repo()->find($this->app->actor()['business_id'], $id), $input, $result['errors'], 422);
        }
        $this->flash('success', 'Producto actualizado.');

        return $this->redirect('/products/' . $id);
    }

    public function saveVariant(Request $request, array $params): Response
    {
        $productId = $this->id($params);
        $variantId = isset($params['vid']) ? (int) $params['vid'] : null;
        $input = $this->input($request);
        $result = $this->service()->saveVariant($this->app->actor(), $productId, $variantId, $input);
        if ($result['status'] === 404) {
            $this->notFound();
        }
        if ($result['ok']) {
            $this->flash('success', $variantId === null ? 'Variante creada.' : 'Variante actualizada.');
        } else {
            $this->flash('danger', implode(' ', $result['errors']));
        }

        return $this->redirect('/products/' . $productId);
    }

    /** @return array<string,mixed> */
    private function input(Request $request): array
    {
        $in = [];
        foreach (['name', 'description', 'category_id', 'brand_id', 'unit_id', 'tax_rate_id', 'sale_price', 'promo_price', 'min_stock', 'status', 'sku', 'barcode', 'variant_name', 'reference_cost'] as $k) {
            $in[$k] = $request->string($k, '');
        }
        $in['price_includes_tax'] = $request->input('price_includes_tax') !== null;
        $in['is_active'] = $request->input('is_active') !== null;

        return $in;
    }

    /** @param array<string,mixed>|null $editing @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(string $mode, ?array $editing, array $values, array $errors, int $status = 200): Response
    {
        return $this->render('products::form', [
            'title' => $mode === 'create' ? 'Nuevo producto' : 'Editar producto', 'mode' => $mode, 'editing' => $editing,
            'values' => $values, 'errors' => $errors, 'options' => $this->repo()->options($this->app->actor()['business_id']),
            'statuses' => ProductService::STATUSES,
        ], $status);
    }

    /**
     * Calculadora de precio con el costo de referencia de la variante predeterminada.
     * v y m llegan por la URL (porcentajes); si faltan o no son válidos solo se muestra el análisis del precio actual.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function calculator(array $p, Request $request): array
    {
        $cost = Decimal::from($p['reference_cost'] ?? 0, 4);
        $v = Decimal::fromInput($request->string('v', '') === '' ? '0' : $request->string('v'), 2);
        $m = Decimal::fromInput($request->string('m', '') === '' ? '0' : $request->string('m'), 2);
        $out = ['cost' => $cost, 'v' => $v ?? Decimal::zero(), 'm' => $m ?? Decimal::zero(), 'error' => null,
            'breakEven' => null, 'recommended' => null, 'net' => null, 'margin' => null, 'markup' => null];
        if ($v === null || $m === null || $v->isNegative() || $m->isNegative()) {
            $out['error'] = 'Los porcentajes deben ser números mayores o iguales a 0.';

            return $out;
        }
        $rate = Decimal::from($p['tax_rate'], 4);
        $net = TaxCalculator::netUnitPrice(Decimal::from($p['sale_price'], 2), $rate, (int) $p['price_includes_tax'] === 1);
        $out['net'] = $net;
        $out['margin'] = PriceCalculator::margin($net, $cost, $v);
        $out['markup'] = PriceCalculator::markup($net, $cost);
        try {
            $out['breakEven'] = PriceCalculator::breakEven($cost, $v);
            if ($m->isPositive()) {
                $out['recommended'] = PriceCalculator::recommended($cost, $v, $m);
            }
        } catch (InvalidArgumentException $e) {
            $out['error'] = $e->getMessage();
        }

        return $out;
    }
}
