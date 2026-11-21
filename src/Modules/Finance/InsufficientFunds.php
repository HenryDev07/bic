<?php

declare(strict_types=1);

namespace Bic\Modules\Finance;

use RuntimeException;

/** Revierte la transacción cuando una operación dejaría una cuenta en negativo. */
final class InsufficientFunds extends RuntimeException
{
}
