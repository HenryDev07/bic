<?php

declare(strict_types=1);

namespace Bic\Core\Money;

use InvalidArgumentException;

/**
 * Fórmulas de precio (ver docs/ARQUITECTURA.md §5). Porcentajes como número (30 = 30 %).
 * C = costo unitario puesto en almacén; v = costos variables % sobre el precio neto;
 * m = margen objetivo % sobre el precio neto; F = costos fijos del período.
 * Los precios mínimos se redondean hacia ARRIBA al centavo: nunca quedan por debajo de lo calculado.
 */
final class PriceCalculator
{
    private static function frac(Decimal $percent): Decimal
    {
        return $percent->rescale(6)->div(Decimal::from(100, 6), 6);
    }

    /** Precio de equilibrio (ganancia = 0): C / (1 − v). */
    public static function breakEven(Decimal $cost, Decimal $variablePct): Decimal
    {
        $d = Decimal::from(1, 6)->sub(self::frac($variablePct));
        if (!$d->isPositive()) {
            throw new InvalidArgumentException('Los costos variables deben ser menores al 100 %.');
        }

        return $cost->rescale(6)->div($d, 2, true);
    }

    /** Precio recomendado: C / (1 − v − m). Exige v + m < 100 %. */
    public static function recommended(Decimal $cost, Decimal $variablePct, Decimal $marginPct): Decimal
    {
        $d = Decimal::from(1, 6)->sub(self::frac($variablePct))->sub(self::frac($marginPct));
        if (!$d->isPositive()) {
            throw new InvalidArgumentException('Costos variables + margen deben ser menores al 100 %.');
        }

        return $cost->rescale(6)->div($d, 2, true);
    }

    /** Margen sobre ventas en %: (P − C − P·v) / P. null si P ≤ 0. */
    public static function margin(Decimal $price, Decimal $cost, Decimal $variablePct): ?Decimal
    {
        if (!$price->isPositive()) {
            return null;
        }
        $variable = $price->rescale(6)->mul(self::frac($variablePct), 6);
        $profit = $price->rescale(6)->sub($cost->rescale(6))->sub($variable);

        return $profit->div($price->rescale(6), 6)->mul(Decimal::from(100, 6), 2);
    }

    /** Markup sobre costo en %: (P − C) / C. null si C ≤ 0. */
    public static function markup(Decimal $price, Decimal $cost): ?Decimal
    {
        if (!$cost->isPositive()) {
            return null;
        }

        return $price->rescale(6)->sub($cost->rescale(6))->div($cost->rescale(6), 6)->mul(Decimal::from(100, 6), 2);
    }

    /** Unidades de equilibrio: F / (P·(1 − v) − C), hacia arriba. null si la contribución unitaria ≤ 0. */
    public static function breakEvenUnits(Decimal $fixed, Decimal $price, Decimal $cost, Decimal $variablePct): ?int
    {
        $unit = $price->rescale(6)->mul(Decimal::from(1, 6)->sub(self::frac($variablePct)), 6)->sub($cost->rescale(6));
        if (!$unit->isPositive()) {
            return null;
        }

        return (int) $fixed->rescale(6)->div($unit, 0, true)->toString();
    }
}
