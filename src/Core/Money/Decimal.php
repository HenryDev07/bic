<?php

declare(strict_types=1);

namespace Bic\Core\Money;

use InvalidArgumentException;
use OverflowException;

/**
 * Número decimal exacto (entero escalado). Sin FLOAT, sin bcmath.
 * Redondeo half-up (0,5 se aleja de cero). Toda operación que desborde lanza excepción.
 */
final class Decimal
{
    private const MAX_SCALE = 8;

    private function __construct(private readonly int $units, private readonly int $scale)
    {
    }

    public static function zero(int $scale = 2): self
    {
        return self::make(0, $scale);
    }

    /** Acepta Decimal, int, string decimal ("12.50", "-3") o float (viene de SUM en SQLite; se redondea a $scale). */
    public static function from(mixed $value, int $scale = 2): self
    {
        self::assertScale($scale);
        if ($value instanceof self) {
            return $value->rescale($scale);
        }
        if (is_int($value)) {
            return self::make(self::mulPow($value, $scale), $scale);
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Número no finito.');
            }
            $value = sprintf('%.' . self::MAX_SCALE . 'F', $value);
        }
        if (!is_string($value) || !preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', trim($value), $m)) {
            throw new InvalidArgumentException('Número decimal no válido.');
        }
        $frac = $m[3] ?? '';
        $keep = substr(str_pad($frac, $scale, '0'), 0, $scale);
        $int = ltrim($m[2], '0') ?: '0';
        $digits = $int . $keep;
        if (strlen(ltrim($digits, '0')) > 18) {
            throw new OverflowException('Número demasiado grande.');
        }
        $units = (int) $digits;
        // Redondeo half-up sobre el primer dígito descartado.
        if (strlen($frac) > $scale && (int) $frac[$scale] >= 5) {
            $units = self::addInts($units, 1);
        }

        return self::make($m[1] === '-' ? -$units : $units, $scale);
    }

    public static function tryFrom(mixed $value, int $scale = 2): ?self
    {
        try {
            return self::from($value, $scale);
        } catch (InvalidArgumentException | OverflowException) {
            return null;
        }
    }

    /** Texto escrito por una persona ("1,234.50", "12,5", " 7 "). Devuelve null si no es un número claro. */
    public static function fromInput(mixed $value, int $scale = 2): ?self
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $text = str_replace(' ', '', trim((string) $value));
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $text)) {
            $text = str_replace(',', '', $text);
        } elseif (substr_count($text, ',') === 1 && !str_contains($text, '.')) {
            $text = str_replace(',', '.', $text);
        }

        return self::tryFrom($text, $scale);
    }

    /** Para mostrar: separador de miles y punto decimal (1,234.50). */
    public function format(): string
    {
        $text = $this->toString();
        $neg = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$int, $frac] = array_pad(explode('.', $text, 2), 2, null);
        $int = (string) preg_replace('/\B(?=(\d{3})+$)/', ',', $int);

        return ($neg ? '-' : '') . $int . ($frac !== null ? '.' . $frac : '');
    }

    public function scale(): int
    {
        return $this->scale;
    }

    public function units(): int
    {
        return $this->units;
    }

    public function add(self $other): self
    {
        [$a, $b, $s] = self::align($this, $other);

        return self::make(self::addInts($a, $b), $s);
    }

    public function sub(self $other): self
    {
        return $this->add($other->negate());
    }

    public function negate(): self
    {
        return self::make(-$this->units, $this->scale);
    }

    public function abs(): self
    {
        return $this->units < 0 ? $this->negate() : $this;
    }

    /** Producto exacto redondeado half-up a $scale (por defecto, la escala de este número). */
    public function mul(self $other, ?int $scale = null): self
    {
        $scale ??= $this->scale;
        self::assertScale($scale);
        $product = $this->units * $other->units;
        if (!is_int($product) || ($this->units !== 0 && intdiv($product, $this->units) !== $other->units)) {
            throw new OverflowException('Desbordamiento al multiplicar.');
        }

        return self::make(self::shift($product, $this->scale + $other->scale, $scale), $scale);
    }

    /** Cociente redondeado a $scale. $ceil = true redondea siempre hacia arriba (precios mínimos). */
    public function div(self $other, ?int $scale = null, bool $ceil = false): self
    {
        if ($other->units === 0) {
            throw new InvalidArgumentException('División entre cero.');
        }
        $scale ??= $this->scale;
        self::assertScale($scale);
        // (a/10^sa)/(b/10^sb) en unidades de 10^-scale = a·10^(scale+sb−sa) / b
        $e = $scale + $other->scale - $this->scale;
        $num = $e >= 0 ? self::mulPow($this->units, $e) : $this->units;
        $den = $e >= 0 ? $other->units : self::mulPow($other->units, -$e);

        return self::make($ceil ? self::ceilDiv($num, $den) : self::halfUpDiv($num, $den), $scale);
    }

    /** Cambia la escala con redondeo half-up. */
    public function rescale(int $scale): self
    {
        self::assertScale($scale);

        return $scale === $this->scale ? $this : self::make(self::shift($this->units, $this->scale, $scale), $scale);
    }

    public function compare(self $other): int
    {
        [$a, $b] = self::align($this, $other);

        return $a <=> $b;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }

    public function isNegative(): bool
    {
        return $this->units < 0;
    }

    public function isPositive(): bool
    {
        return $this->units > 0;
    }

    public function toString(): string
    {
        $abs = abs($this->units);
        $digits = str_pad((string) $abs, $this->scale + 1, '0', STR_PAD_LEFT);
        $text = $this->scale === 0 ? $digits : substr($digits, 0, -$this->scale) . '.' . substr($digits, -$this->scale);

        return ($this->units < 0 ? '-' : '') . $text;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    // ---- internos ----

    private static function make(int $units, int $scale): self
    {
        return new self($units, $scale);
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw new InvalidArgumentException('Escala fuera de rango (0–' . self::MAX_SCALE . ').');
        }
    }

    private static function pow10(int $n): int
    {
        if ($n < 0 || $n > 18) {
            throw new OverflowException('Desbordamiento de escala.');
        }

        return (int) (10 ** $n);
    }

    private static function addInts(int $a, int $b): int
    {
        $sum = $a + $b;
        if (!is_int($sum)) {
            throw new OverflowException('Desbordamiento al sumar.');
        }

        return $sum;
    }

    private static function mulPow(int $value, int $n): int
    {
        $result = $value * self::pow10($n);
        if (!is_int($result) || ($value !== 0 && intdiv($result, $value) !== self::pow10($n))) {
            throw new OverflowException('Desbordamiento.');
        }

        return $result;
    }

    /** Pasa un valor con escala $from a escala $to. */
    private static function shift(int $units, int $from, int $to): int
    {
        if ($to >= $from) {
            return self::mulPow($units, $to - $from);
        }
        $diff = $from - $to;
        if ($diff > 18) {
            return 0;
        }

        return self::halfUpDiv($units, self::pow10($diff));
    }

    /** @return array{0:int,1:int,2:int} */
    private static function align(self $a, self $b): array
    {
        $scale = max($a->scale, $b->scale);

        return [self::shift($a->units, $a->scale, $scale), self::shift($b->units, $b->scale, $scale), $scale];
    }

    private static function halfUpDiv(int $n, int $d): int
    {
        $sign = ($n < 0) !== ($d < 0) ? -1 : 1;
        $n = abs($n);
        $d = abs($d);
        $twice = $n * 2;
        if (!is_int($twice)) {
            throw new OverflowException('Desbordamiento al dividir.');
        }
        $q = intdiv($twice + $d, 2 * $d);

        return $sign * $q;
    }

    private static function ceilDiv(int $n, int $d): int
    {
        $q = intdiv($n, $d);
        if ($n % $d !== 0 && ($n < 0) === ($d < 0)) {
            $q++;
        }

        return $q;
    }
}
