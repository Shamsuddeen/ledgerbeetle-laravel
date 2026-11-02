<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use LedgerBeetle\Laravel\Contracts\TigerBeetleGateway;
use LedgerBeetle\Laravel\Exceptions\IdempotencyConflict;
use LedgerBeetle\Laravel\Exceptions\TransferRejected;
use LedgerBeetle\Laravel\Gateways\Data\CreateOutcome;
use LedgerBeetle\Laravel\Gateways\Data\TransferSpec;
use LedgerBeetle\Laravel\Models\LedgerConversion;
use LedgerBeetle\Laravel\Models\LedgerHold;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Support\Id;

/**
 * The write path every money movement goes through:
 *
 *  1. claim()  records the intent under its idempotency key with a fresh TB id.
 *  2. submit() sends it to TigerBeetle and records the outcome.
 *
 * A retry with the same key finds the same row, so it resends the same TB id
 * and TigerBeetle answers EXISTS instead of moving money twice. A crash
 * between the steps leaves the row "pending" for ledger:reconcile to settle.
 */
final class TransferWriter
{
    public function __construct(
        private readonly TigerBeetleGateway $gateway,
    ) {}

    /**
     * @template T of LedgerTransfer|LedgerHold|LedgerConversion
     *
     * @param  class-string<T>  $model
     * @param  array<string, mixed>  $fingerprint  the parameters a retry must repeat exactly
     * @param  array<string, mixed>  $attributes
     * @param  bool  $withTbId  false for records that are not TB objects themselves
     * @return T
     */
    public function claim(string $model, string $key, array $fingerprint, array $attributes, bool $withTbId = true): Model
    {
        $hash = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));

        $row = $model::query()->where('idempotency_key', $key)->first();

        if ($row === null) {
            try {
                return $model::query()->create($attributes + [
                    'idempotency_key' => $key,
                    'fingerprint' => $hash,
                    'status' => 'pending',
                ] + ($withTbId ? ['tb_id' => Id::generate()] : []));
            } catch (UniqueConstraintViolationException) {
                // Lost a race with a concurrent request using the same key.
                $row = $model::query()->where('idempotency_key', $key)->firstOrFail();
            }
        }

        if (! hash_equals($row->fingerprint, $hash)) {
            throw IdempotencyConflict::key($key);
        }

        return $row;
    }

    /**
     * Send a claimed row to TigerBeetle unless an earlier attempt already did.
     *
     * @template T of LedgerTransfer|LedgerHold
     *
     * @param  T  $row
     * @param  Closure(CreateOutcome): array<string, mixed>  $onSuccess  attributes to store when TB accepts it
     * @return T
     *
     * @throws TransferRejected
     */
    public function submit(Model $row, TransferSpec $spec, Closure $onSuccess): Model
    {
        if ($row->status->value === 'failed') {
            throw TransferRejected::fromRecorded($row->failure_reason);
        }

        if ($row->status->value !== 'pending') {
            return $row;
        }

        [$outcome] = $this->gateway->createTransfers([$spec]);

        if ($outcome->ok()) {
            $row->update($onSuccess($outcome));

            return $row;
        }

        $row->update(['status' => 'failed', 'failure_reason' => $outcome->result->name]);

        throw TransferRejected::for($outcome->result);
    }
}
