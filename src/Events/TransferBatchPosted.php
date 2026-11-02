<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A queued batch finished. Failed items are recorded on their transfer rows
 * (status "failed") or, if they never got a row, listed here.
 */
final class TransferBatchPosted
{
    use Dispatchable;

    /**
     * @param  list<string>  $posted  idempotency keys now posted
     * @param  array<string, string>  $failed  idempotency key => reason
     */
    public function __construct(
        public readonly string $batchId,
        public readonly array $posted,
        public readonly array $failed,
    ) {}
}
