<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Gateways\Data;

final readonly class AccountState
{
    public function __construct(
        public string $id,
        public int $ledger,
        public int $code,
        public int $flags,
        public int $debitsPending,
        public int $debitsPosted,
        public int $creditsPending,
        public int $creditsPosted,
        public int $timestamp,
        public string $userData128 = '0',
        public int $userData64 = 0,
        public int $userData32 = 0,
    ) {}
}
