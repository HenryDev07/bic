<?php

declare(strict_types=1);

namespace Bic\Core\Money;

use InvalidArgumentException;

/** Importe monetario con moneda: 2 decimales, mismo tipo de moneda en toda operación. */
final class Money
{
    private function __construct(private readonly Decimal $amount, private readonly string $currency)
    {
    }

    public static function of(mixed $amount, string $currency = 'USD'): self
    {
        $currency = strtoupper($currency);
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Moneda no válida (código de 3 letras).');
        }

        return new self(Decimal::from($amount, 2), $currency);
    }

    public static function zero(string $currency = 'USD'): self
    {
        return self::of(0, $currency);
    }

    public function amount(): Decimal
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $o): self
    {
        $this->same($o);

        return new self($this->amount->add($o->amount), $this->currency);
    }

    public function sub(self $o): self
    {
        $this->same($o);

        return new self($this->amount->sub($o->amount), $this->currency);
    }

    public function negate(): self
    {
        return new self($this->amount->negate(), $this->currency);
    }

    /** Multiplica por una cantidad o factor y redondea a 2 decimales (half-up). */
    public function times(Decimal $factor): self
    {
        return new self($this->amount->mul($factor, 2), $this->currency);
    }

    /** Convierte con un tipo de cambio: 1 unidad de esta moneda = $rate unidades de $to. */
    public function convert(Decimal $rate, string $to): self
    {
        if (!$rate->isPositive()) {
            throw new InvalidArgumentException('El tipo de cambio debe ser mayor que cero.');
        }

        return self::of($this->amount->mul($rate, 2), $to);
    }

    public function compare(self $o): int
    {
        $this->same($o);

        return $this->amount->compare($o->amount);
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function toString(): string
    {
        return $this->amount->toString();
    }

    private function same(self $o): void
    {
        if ($o->currency !== $this->currency) {
            throw new InvalidArgumentException("No se pueden combinar {$this->currency} y {$o->currency} sin convertir.");
        }
    }
}
