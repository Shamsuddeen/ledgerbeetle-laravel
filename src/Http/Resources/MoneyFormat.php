<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http\Resources;

use LedgerBeetle\Laravel\Support\Money;

/**
 * Amounts go out both as integer minor units and as exact decimal strings.
 */
trait MoneyFormat
{
    /**
     * @return array{minor: int, decimal: string, currency: string}
     */
    protected function money(int $minor, string $currency): array
    {
        return ['minor' => $minor, 'decimal' => Money::of($minor, $currency)->toDecimal(), 'currency' => $currency];
    }
}
