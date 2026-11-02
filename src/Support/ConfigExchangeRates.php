<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Support;

use LedgerBeetle\Laravel\Contracts\ExchangeRates;
use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;

/**
 * Fixed rates from config, keyed "FROM/TO". The inverse pair is derived when
 * only one direction is configured.
 */
final class ConfigExchangeRates implements ExchangeRates
{
    /**
     * @param  array<string, string>  $rates
     */
    public function __construct(
        private readonly array $rates,
    ) {}

    public function rate(string $from, string $to): string
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($rate = $this->rates["{$from}/{$to}"] ?? null) {
            return (string) $rate;
        }

        if ($inverse = $this->rates["{$to}/{$from}"] ?? null) {
            return bcdiv('1', (string) $inverse, 12);
        }

        throw new InvalidLedgerOperation("No exchange rate configured for {$from}/{$to}.");
    }
}
