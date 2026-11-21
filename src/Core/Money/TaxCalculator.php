<?php

declare(strict_types=1);

namespace Bic\Core\Money;

/**
 * Impuesto de una línea. Tasa en porcentaje (16 = 16 %). Redondeo half-up a 2 decimales POR LÍNEA;
 * el total de un documento es la suma de líneas ya redondeadas.
 */
final class TaxCalculator
{
    /** @return array{net:Decimal,tax:Decimal,gross:Decimal} */
    public static function line(Decimal $unitPrice, Decimal $quantity, Decimal $ratePercent, bool $priceIncludesTax): array
    {
        $amount = $unitPrice->mul($quantity, 2);
        $hundred = Decimal::from(100, 4);
        if ($priceIncludesTax) {
            $net = $amount->mul($hundred, 2)->div($hundred->add($ratePercent->rescale(4)), 2);
            $tax = $amount->sub($net);

            return ['net' => $net, 'tax' => $tax, 'gross' => $amount];
        }
        $tax = $amount->mul($ratePercent, 2)->div($hundred, 2);

        return ['net' => $amount, 'tax' => $tax, 'gross' => $amount->add($tax)];
    }

    /** Precio unitario sin impuesto (escala 4, para análisis de margen). */
    public static function netUnitPrice(Decimal $price, Decimal $ratePercent, bool $priceIncludesTax): Decimal
    {
        if (!$priceIncludesTax) {
            return $price->rescale(4);
        }
        $hundred = Decimal::from(100, 4);

        return $price->rescale(4)->mul($hundred, 4)->div($hundred->add($ratePercent->rescale(4)), 4);
    }
}
