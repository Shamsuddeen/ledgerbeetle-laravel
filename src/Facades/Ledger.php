<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \LedgerBeetle\Laravel\Models\LedgerAccount createAccount(string $code, string $name, \LedgerBeetle\Laravel\Enums\AccountType $type, ?string $currency = null, \LedgerBeetle\Laravel\Models\LedgerAccount|string|null $parent = null, ?bool $preventOverdraft = null, array $metadata = [])
 * @method static \LedgerBeetle\Laravel\Models\LedgerAccount account(string $code)
 * @method static \LedgerBeetle\Laravel\Support\Balance balance(\LedgerBeetle\Laravel\Models\LedgerAccount|string $account)
 * @method static list<\LedgerBeetle\Laravel\Gateways\Data\TransferState> history(\LedgerBeetle\Laravel\Models\LedgerAccount|string $account, int $limit = 100, bool $newestFirst = true)
 * @method static \LedgerBeetle\Laravel\Models\LedgerTransfer transfer(\LedgerBeetle\Laravel\Models\LedgerAccount|string $from, \LedgerBeetle\Laravel\Models\LedgerAccount|string $to, \LedgerBeetle\Laravel\Support\Money|int $amount, ?string $idempotencyKey = null, ?string $reference = null, ?int $code = null, array $metadata = [])
 * @method static list<\LedgerBeetle\Laravel\Models\LedgerTransfer> transferMany(array $legs, string $idempotencyKey)
 * @method static \LedgerBeetle\Laravel\Models\LedgerHold hold(\LedgerBeetle\Laravel\Models\LedgerAccount|string $from, \LedgerBeetle\Laravel\Models\LedgerAccount|string $to, \LedgerBeetle\Laravel\Support\Money|int $amount, int $timeoutSeconds = 0, ?string $idempotencyKey = null, ?string $reference = null, ?int $code = null, array $metadata = [])
 * @method static \LedgerBeetle\Laravel\Models\LedgerTransfer capture(\LedgerBeetle\Laravel\Models\LedgerHold|int $hold, ?int $amount = null, ?string $idempotencyKey = null)
 * @method static \LedgerBeetle\Laravel\Models\LedgerTransfer release(\LedgerBeetle\Laravel\Models\LedgerHold|int $hold, ?string $idempotencyKey = null)
 * @method static \LedgerBeetle\Laravel\Models\LedgerTransfer reverse(\LedgerBeetle\Laravel\Models\LedgerTransfer|int $transfer, ?int $amount = null, ?string $idempotencyKey = null, ?string $reason = null)
 *
 * @see \LedgerBeetle\Laravel\Ledger
 */
class Ledger extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LedgerBeetle\Laravel\Ledger::class;
    }
}
