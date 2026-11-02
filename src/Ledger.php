<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel;

use DateTimeInterface;
use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Gateways\Data\TransferState;
use LedgerBeetle\Laravel\Jobs\PostTransferBatch;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Models\LedgerConversion;
use LedgerBeetle\Laravel\Models\LedgerHold;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Services\AccountService;
use LedgerBeetle\Laravel\Services\ConversionService;
use LedgerBeetle\Laravel\Services\HoldService;
use LedgerBeetle\Laravel\Services\ReversalService;
use LedgerBeetle\Laravel\Services\TransferService;
use LedgerBeetle\Laravel\Support\Balance;
use LedgerBeetle\Laravel\Support\BalanceSnapshot;
use LedgerBeetle\Laravel\Support\Money;

/**
 * One entry point over the services; backs the Ledger facade.
 */
final class Ledger
{
    public function __construct(
        public readonly AccountService $accounts,
        public readonly TransferService $transfers,
        public readonly HoldService $holds,
        public readonly ReversalService $reversals,
        public readonly ConversionService $conversions,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function createAccount(
        string $code,
        string $name,
        AccountType $type,
        ?string $currency = null,
        LedgerAccount|string|null $parent = null,
        ?bool $preventOverdraft = null,
        array $metadata = [],
        ?bool $history = null,
    ): LedgerAccount {
        return $this->accounts->create($code, $name, $type, $currency, $parent, $preventOverdraft, $metadata, $history);
    }

    public function account(string $code): LedgerAccount
    {
        return $this->accounts->find($code);
    }

    public function balance(LedgerAccount|string $account): Balance
    {
        return $this->accounts->balance($account);
    }

    public function balanceAt(LedgerAccount|string $account, DateTimeInterface $at): Balance
    {
        return $this->accounts->balanceAt($account, $at);
    }

    /**
     * @return list<BalanceSnapshot>
     */
    public function balanceHistory(
        LedgerAccount|string $account,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        int $limit = 100,
        bool $newestFirst = true,
    ): array {
        return $this->accounts->balanceHistory($account, $from, $to, $limit, $newestFirst);
    }

    /**
     * @return list<TransferState>
     */
    public function history(LedgerAccount|string $account, int $limit = 100, bool $newestFirst = true): array
    {
        return $this->accounts->history($account, $limit, $newestFirst);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transfer(
        LedgerAccount|string $from,
        LedgerAccount|string $to,
        Money|int $amount,
        ?string $idempotencyKey = null,
        ?string $reference = null,
        ?int $code = null,
        array $metadata = [],
    ): LedgerTransfer {
        return $this->transfers->transfer($from, $to, $amount, $idempotencyKey, $reference, $code, $metadata);
    }

    /**
     * @param  list<array{from: LedgerAccount|string, to: LedgerAccount|string, amount: Money|int, reference?: string, code?: int}>  $legs
     * @return list<LedgerTransfer>
     */
    public function transferMany(array $legs, string $idempotencyKey): array
    {
        return $this->transfers->transferMany($legs, $idempotencyKey);
    }

    /**
     * @param  list<array{from: LedgerAccount|string, to: LedgerAccount|string, amount: Money|int, idempotency_key: string, reference?: string|null, code?: int|null}>  $items
     * @return array<string, LedgerTransfer|\LedgerBeetle\Laravel\Exceptions\LedgerException>
     */
    public function transferBatch(array $items): array
    {
        return $this->transfers->transferBatch($items);
    }

    /**
     * Queue a batch for PostTransferBatch. Accounts by code, amounts in minor units.
     *
     * @param  list<array{from: string, to: string, amount: int, idempotency_key: string, reference?: string|null, code?: int|null}>  $items
     * @return string the batch id reported by the TransferBatchPosted event
     */
    public function queueBatch(array $items): string
    {
        $job = new PostTransferBatch($items);
        dispatch($job);

        return $job->batchId;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function hold(
        LedgerAccount|string $from,
        LedgerAccount|string $to,
        Money|int $amount,
        int $timeoutSeconds = 0,
        ?string $idempotencyKey = null,
        ?string $reference = null,
        ?int $code = null,
        array $metadata = [],
    ): LedgerHold {
        return $this->holds->place($from, $to, $amount, $timeoutSeconds, $idempotencyKey, $reference, $code, $metadata);
    }

    public function capture(LedgerHold|int $hold, ?int $amount = null, ?string $idempotencyKey = null): LedgerTransfer
    {
        return $this->holds->capture($hold, $amount, $idempotencyKey);
    }

    public function release(LedgerHold|int $hold, ?string $idempotencyKey = null): LedgerTransfer
    {
        return $this->holds->release($hold, $idempotencyKey);
    }

    public function reverse(LedgerTransfer|int $transfer, ?int $amount = null, ?string $idempotencyKey = null, ?string $reason = null): LedgerTransfer
    {
        return $this->reversals->reverse($transfer, $amount, $idempotencyKey, $reason);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function convert(
        LedgerAccount|string $from,
        LedgerAccount|string $to,
        Money|int $amount,
        ?string $rate = null,
        ?string $idempotencyKey = null,
        Money|int $fee = 0,
        LedgerAccount|string|null $feeAccount = null,
        ?string $reference = null,
        array $metadata = [],
    ): LedgerConversion {
        return $this->conversions->convert($from, $to, $amount, $rate, $idempotencyKey, $fee, $feeAccount, $reference, $metadata);
    }

    public function reverseConversion(LedgerConversion|int $conversion, ?string $idempotencyKey = null): LedgerConversion
    {
        return $this->conversions->reverse($conversion, $idempotencyKey);
    }
}
