<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Contracts;

/**
 * Source of exchange rates for conversions. Bind your own implementation to
 * use a market data provider; the default reads config('ledger.fx.rates').
 */
interface ExchangeRates
{
    /**
     * Units of $to per one unit of $from, as a positive decimal string.
     */
    public function rate(string $from, string $to): string;
}
