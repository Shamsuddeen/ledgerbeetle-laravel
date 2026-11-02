<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use LedgerBeetle\Laravel\Events\TransferBatchPosted;
use LedgerBeetle\Laravel\Exceptions\TransferRejected;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Services\TransferService;

/**
 * Posts a batch of independent transfers off the request path.
 *
 * Every item carries its own idempotency key, so the job is safe to retry:
 * already-posted items are skipped and only unsent ones go to TigerBeetle.
 * ClusterUnavailable propagates so the queue retries the job.
 */
final class PostTransferBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 60];

    public readonly string $batchId;

    /**
     * Accounts by code, amounts in minor units.
     *
     * @param  list<array{from: string, to: string, amount: int, idempotency_key: string, reference?: string|null, code?: int|null}>  $items
     */
    public function __construct(
        public readonly array $items,
        ?string $batchId = null,
    ) {
        $this->batchId = $batchId ?? (string) Str::uuid();
        $this->onConnection(config('ledger.queue.connection'));
        $this->onQueue(config('ledger.queue.name'));
    }

    public function handle(TransferService $transfers): void
    {
        $posted = $failed = [];

        foreach ($transfers->transferBatch($this->items) as $key => $result) {
            if ($result instanceof LedgerTransfer) {
                $posted[] = $key;
            } else {
                $failed[$key] = $result instanceof TransferRejected ? strtolower($result->result->name) : $result->getMessage();
            }
        }

        TransferBatchPosted::dispatch($this->batchId, $posted, $failed);
    }
}
