<?php

declare(strict_types=1);

namespace Bic\Modules\Inventory;

use RuntimeException;

/** Revierte la transacción cuando una salida superaría el stock disponible. */
final class InsufficientStock extends RuntimeException
{
}
