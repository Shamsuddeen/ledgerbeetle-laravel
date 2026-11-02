<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Gateways\Data;

/**
 * An account's totals right after the transfer with this timestamp.
 */
final readonly class AccountBalanceState
{
    public function __construct(
        public int $debitsPending,
        public int $debitsPosted,
        public int $creditsPending,
        public int $creditsPosted,
        public int $timestamp,
    ) {}
}
