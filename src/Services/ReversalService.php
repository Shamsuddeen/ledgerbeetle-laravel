<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LedgerBeetle\Laravel\Enums\TransferKind;
use LedgerBeetle\Laravel\Enums\TransferStatus;
use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;
use LedgerBeetle\Laravel\Exceptions\TransferRejected;
use LedgerBeetle\Laravel\Gateways\Data\TransferSpec;
use LedgerBeetle\Laravel\Models\LedgerTransfer;

/**
 * Posted transfers are immutable in TigerBeetle. A reversal is a new transfer
 * in the opposite direction, linked to the original through user_data_128.
 */
final class ReversalService
{
    public function __construct(
        private readonly TransferWriter $writer,
    ) {}

    /**
     * Reverse all or part of a posted transfer. The total reversed can never
     * exceed the original amount, even under concurrent calls.
     *
     * @param  int|null  $amount  null reverses whatever has not been reversed yet
     */
    public function reverse(
        LedgerTransfer|int $transfer,
        ?int $amount = null,
        ?string $idempotencyKey = null,
        ?string $reason = null,
    ): LedgerTransfer {
        $key = $idempotencyKey ?? (string) Str::uuid();
        $originalId = $transfer instanceof LedgerTransfer ? $transfer->id : $transfer;

        // Lock the original so concurrent reversals see each other's reservations.
        [$original, $row] = DB::connection(config('ledger.database_connection'))->transaction(function () use ($originalId, $amount, $key, $reason) {
            $original = LedgerTransfer::query()->lockForUpdate()->findOrFail($originalId);

            if ($original->status !== TransferStatus::POSTED
                || ! in_array($original->kind, [TransferKind::TRANSFER, TransferKind::HOLD_POST], true)) {
                throw new InvalidLedgerOperation("Transfer {$original->id} is not a posted transfer that can be reversed.");
            }

            // On a retry the amount was reserved by the first attempt.
            $existing = LedgerTransfer::query()->where('idempotency_key', $key)->first();
            $amount ??= $existing->amount ?? $original->reversibleAmount();

            if ($existing === null && ($amount <= 0 || $amount > $original->reversibleAmount())) {
                throw new InvalidLedgerOperation(
                    "Reversal amount must be between 1 and {$original->reversibleAmount()} for transfer {$original->id}."
                );
            }

            $row = $this->writer->claim(LedgerTransfer::class, $key, [
                'kind' => 'reversal', 'of' => $original->id, 'amount' => $amount,
            ], [
                'kind' => TransferKind::REVERSAL,
                'reverses_id' => $original->id,
                'debit_account_id' => $original->credit_account_id,
                'credit_account_id' => $original->debit_account_id,
                'amount' => $amount,
                'currency' => $original->currency,
                'ledger' => $original->ledger,
                'tb_code' => $original->tb_code,
                'reference' => $reason,
            ]);

            if ($row->wasRecentlyCreated) {
                $original->increment('reversed_amount', $amount);
            }

            return [$original, $row];
        });

        $wasPending = $row->status === TransferStatus::PENDING;

        try {
            return $this->writer->submit($row, new TransferSpec(
                id: $row->tb_id,
                debitAccountId: $original->creditAccount->tb_id,
                creditAccountId: $original->debitAccount->tb_id,
                amount: $row->amount,
                ledger: $row->ledger,
                code: $row->tb_code,
                userData128: $original->tb_id,
            ), TransferService::posted(...));
        } catch (TransferRejected $e) {
            // TB refused it for good: give the reserved amount back.
            if ($wasPending) {
                LedgerTransfer::query()->whereKey($original->id)->decrement('reversed_amount', $row->amount);
            }

            throw $e;
        }
    }
}
