<?php

declare(strict_types=1);

namespace Bic\Tests\Money;

use Bic\Core\Money\Decimal;
use Bic\Core\Money\Money;
use Bic\Core\Money\PriceCalculator;
use Bic\Core\Money\TaxCalculator;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    private function d(string $v, int $s = 2): Decimal
    {
        return Decimal::from($v, $s);
    }

    public function testParsingAndHalfUpRounding(): void
    {
        $this->assertSame('2.68', $this->d('2.675')->toString());
        $this->assertSame('-2.68', $this->d('-2.675')->toString());
        $this->assertSame('2.67', $this->d('2.674')->toString());
        $this->assertSame('0.30', Decimal::from(0.1 + 0.2, 2)->toString(), 'el ruido binario de SUM en SQLite se elimina');
        $this->assertSame('5.00', Decimal::from(5, 2)->toString());
        $this->assertSame('-0.05', $this->d('-0.05')->toString());
        $this->assertNull(Decimal::tryFrom('abc'));
        $this->assertNull(Decimal::tryFrom('1e5'));
    }

    public function testHumanInput(): void
    {
        $this->assertSame('1234.50', Decimal::fromInput('1,234.50')->toString());
        $this->assertSame('12.50', Decimal::fromInput('12,5')->toString());
        $this->assertSame('7.00', Decimal::fromInput(' 7 ')->toString());
        $this->assertNull(Decimal::fromInput('12..5'));
        $this->assertNull(Decimal::fromInput(['1']));
        $this->assertSame('1,234,567.80', $this->d('1234567.8')->format());
        $this->assertSame('-999.00', $this->d('-999')->format());
    }

    public function testArithmeticIsExact(): void
    {
        $total = Decimal::zero();
        for ($i = 0; $i < 10; $i++) {
            $total = $total->add($this->d('0.10'));
        }
        $this->assertSame('1.00', $total->toString(), '10 × 0.10 = 1.00 exacto');
        $this->assertSame('3.03', $this->d('1.005')->mul($this->d('3', 0), 2)->toString());
        $this->assertSame('3.33', $this->d('10')->div($this->d('3', 0), 2)->toString());
        $this->assertSame('3.34', $this->d('10')->div($this->d('3', 0), 2, true)->toString(), 'ceil sube');
        $this->assertSame('-3.33', $this->d('-10')->div($this->d('3', 0), 2)->toString());
        $this->assertSame(1, $this->d('1.11')->compare($this->d('1.1', 1)));
        $this->assertSame(0, $this->d('1.10')->compare($this->d('1.1', 1)));
        $this->assertTrue($this->d('1.10')->equals($this->d('1.1', 1)));
    }

    public function testDivisionByZeroAndOverflow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->d('1')->div(Decimal::zero());
    }

    public function testOverflowIsDetected(): void
    {
        $this->expectException(OverflowException::class);
        Decimal::from('9999999999999999', 2)->mul(Decimal::from('9999999999999999', 2), 2);
    }

    public function testMoneyRulesAndConversion(): void
    {
        $a = Money::of('10.10', 'usd');
        $this->assertSame('USD', $a->currency());
        $this->assertSame('15.15', $a->add(Money::of('5.05'))->toString());
        $this->assertSame('5.05', $a->sub(Money::of('5.05'))->toString());
        $this->assertSame('8.75', Money::of('100')->convert(Decimal::from('0.0875', 8), 'EUR')->toString());
        $this->assertSame('EUR', Money::of('100')->convert(Decimal::from('0.5', 8), 'EUR')->currency());
        $this->assertSame('3.00', Money::of('1.00')->times(Decimal::from(3, 0))->toString());
        $this->assertTrue(Money::of('-1')->isNegative());
    }

    public function testMoneyRejectsMixedCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1', 'USD')->add(Money::of('1', 'EUR'));
    }

    public function testTaxExclusiveAndInclusive(): void
    {
        $excl = TaxCalculator::line($this->d('10.00'), Decimal::from(3, 3), $this->d('16', 4), false);
        $this->assertSame(['30.00', '4.80', '34.80'], [$excl['net']->toString(), $excl['tax']->toString(), $excl['gross']->toString()]);
        $incl = TaxCalculator::line($this->d('11.60'), Decimal::from(1, 3), $this->d('16', 4), true);
        $this->assertSame(['10.00', '1.60', '11.60'], [$incl['net']->toString(), $incl['tax']->toString(), $incl['gross']->toString()]);
        $zero = TaxCalculator::line($this->d('9.99'), Decimal::from(2, 3), $this->d('0', 4), true);
        $this->assertSame(['19.98', '0.00'], [$zero['net']->toString(), $zero['tax']->toString()]);
        $this->assertSame('10.0000', TaxCalculator::netUnitPrice($this->d('11.60'), $this->d('16', 4), true)->toString());
    }

    public function testTaxRoundsPerLine(): void
    {
        // 3 líneas de 0.33 al 10 %: cada impuesto (0.033) se redondea a 0.03 → total 0.09, no 0.099 → 0.10
        $sum = Decimal::zero();
        for ($i = 0; $i < 3; $i++) {
            $sum = $sum->add(TaxCalculator::line($this->d('0.33'), Decimal::from(1, 3), $this->d('10', 4), false)['tax']);
        }
        $this->assertSame('0.09', $sum->toString());
    }

    public function testPriceFormulas(): void
    {
        $c = $this->d('70', 4);
        $this->assertSame('77.78', PriceCalculator::breakEven($c, $this->d('10'))->toString());
        $this->assertSame('100.00', PriceCalculator::recommended($c, $this->d('10'), $this->d('20'))->toString());
        $this->assertSame('20.00', PriceCalculator::margin($this->d('100'), $c, $this->d('10'))->toString());
        $this->assertSame('42.86', PriceCalculator::markup($this->d('100'), $c)->toString());
        $this->assertSame(50, PriceCalculator::breakEvenUnits($this->d('1000'), $this->d('100'), $c, $this->d('10')));
        $this->assertNull(PriceCalculator::breakEvenUnits($this->d('1000'), $this->d('70'), $c, $this->d('10')), 'sin contribución no hay equilibrio');
        $this->assertNull(PriceCalculator::margin(Decimal::zero(), $c, Decimal::zero()));
        $this->assertNull(PriceCalculator::markup($this->d('10'), Decimal::zero(4)));
    }

    public function testBreakEvenNeverBelowRealBreakEven(): void
    {
        $be = PriceCalculator::breakEven($this->d('10', 4), $this->d('3'));
        $this->assertSame('10.31', $be->toString()); // 10/0.97 = 10.3092… sube al centavo
        $this->assertTrue(PriceCalculator::margin($be, $this->d('10', 4), $this->d('3'))->compare(Decimal::zero()) >= 0);
    }

    public function testRecommendedNeedsVariablePlusMarginBelow100(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PriceCalculator::recommended($this->d('10', 4), $this->d('60'), $this->d('40'));
    }
}
