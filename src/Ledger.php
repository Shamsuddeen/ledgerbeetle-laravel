<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel;

use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Gateways\Data\TransferState;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Models\LedgerHold;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Services\AccountService;
use LedgerBeetle\Laravel\Services\HoldService;
use LedgerBeetle\Laravel\Services\ReversalService;
use LedgerBeetle\Laravel\Services\TransferService;
use LedgerBeetle\Laravel\Support\Balance;
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
    ): LedgerAccount {
        return $this->accounts->create($code, $name, $type, $currency, $parent, $preventOverdraft, $metadata);
    }

    public function account(string $code): LedgerAccount
    {
        return $this->accounts->find($code);
    }

    public function balance(LedgerAccount|string $account): Balance
    {
        return $this->accounts->balance($account);
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
}
