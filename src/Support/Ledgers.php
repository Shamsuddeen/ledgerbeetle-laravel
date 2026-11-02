<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Support;

use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;

/**
 * Currency → TigerBeetle ledger mapping from config('ledger.ledgers').
 */
final class Ledgers
{
    /**
     * @param  array<string, array{ledger: int, scale: int}>  $ledgers
     */
    public function __construct(
        private readonly array $ledgers,
    ) {}

    public function ledgerFor(string $currency): int
    {
        return $this->get($currency)['ledger'];
    }

    public function scaleFor(string $currency): int
    {
        return $this->get($currency)['scale'];
    }

    /**
     * @return array{ledger: int, scale: int}
     */
    private function get(string $currency): array
    {
        return $this->ledgers[strtoupper($currency)]
            ?? throw new InvalidLedgerOperation("Currency [{$currency}] has no ledger configured in ledger.ledgers.");
    }
}
