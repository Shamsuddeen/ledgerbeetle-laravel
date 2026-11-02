<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Gateways\Data;

final readonly class TransferSpec
{
    /**
     * @param  int|null  $amount  null posts the full pending amount (TB's AMOUNT_MAX);
     *                            only valid with the post_pending_transfer flag.
     * @param  string  $debitAccountId  may be '0' for post/void, TB copies it from the pending transfer
     */
    public function __construct(
        public string $id,
        public string $debitAccountId,
        public string $creditAccountId,
        public ?int $amount,
        public int $ledger,
        public int $code,
        public int $flags = 0,
        public string $pendingId = '0',
        public int $timeout = 0,
        public string $userData128 = '0',
        public int $userData64 = 0,
        public int $userData32 = 0,
    ) {}
}
