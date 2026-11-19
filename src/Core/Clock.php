<?php

declare(strict_types=1);

namespace Bic\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Reloj en UTC; se puede fijar en pruebas. */
final class Clock
{
    public function __construct(private ?DateTimeImmutable $fixed = null)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Formato DATETIME de MySQL (UTC). */
    public function sql(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    public function advance(int $seconds): void
    {
        if ($this->fixed !== null) {
            $this->fixed = $this->fixed->modify(sprintf('%+d seconds', $seconds));
        }
    }
}
