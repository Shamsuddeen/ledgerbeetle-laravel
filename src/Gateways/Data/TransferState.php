<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Gateways\Data;

final readonly class TransferState
{
    public function __construct(
        public string $id,
        public string $debitAccountId,
        public string $creditAccountId,
        public int $amount,
        public string $pendingId,
        public int $ledger,
        public int $code,
        public int $flags,
        public int $timeout,
        public int $timestamp,
        public string $userData128 = '0',
        public int $userData64 = 0,
        public int $userData32 = 0,
    ) {}
}
