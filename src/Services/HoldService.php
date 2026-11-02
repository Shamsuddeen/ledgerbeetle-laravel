<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LedgerBeetle\Laravel\Enums\CreateTransferResult;
use LedgerBeetle\Laravel\Enums\HoldStatus;
use LedgerBeetle\Laravel\Enums\TransferKind;
use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;
use LedgerBeetle\Laravel\Exceptions\TransferRejected;
use LedgerBeetle\Laravel\Gateways\Data\TransferSpec;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Models\LedgerHold;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Support\Flags;
use LedgerBeetle\Laravel\Support\Money;

/**
 * Holds are TigerBeetle pending transfers: the funds leave the payer's
 * available balance immediately and are posted (in full or in part) or
 * voided later. TB voids them on its own once their timeout passes.
 */
final class HoldService
{
    public function __construct(
        private readonly TransferWriter $writer,
        private readonly AccountService $accounts,
        private readonly int $defaultCode,
    ) {}

    /**
     * Reserve funds that will move from $from to $to when captured.
     *
     * @param  int  $timeoutSeconds  0 means the hold never expires on its own
     * @param  array<string, mixed>  $metadata
     */
    public function place(
        LedgerAccount|string $from,
        LedgerAccount|string $to,
        Money|int $amount,
        int $timeoutSeconds = 0,
        ?string $idempotencyKey = null,
        ?string $reference = null,
        ?int $code = null,
        array $metadata = [],
    ): LedgerHold {
        $from = $this->accounts->resolve($from);
        $to = $this->accounts->resolve($to);
        $code ??= $this->defaultCode;
        $minor = $amount instanceof Money ? $amount->minor : $amount;

        if ($from->currency !== $to->currency || ($amount instanceof Money && $amount->currency !== $from->currency)) {
            throw new InvalidLedgerOperation('A hold needs both accounts and the amount in the same currency.');
        }
        if ($minor <= 0 || $timeoutSeconds < 0) {
            throw new InvalidLedgerOperation('Hold amount must be positive and its timeout non-negative.');
        }

        $hold = $this->writer->claim(LedgerHold::class, $idempotencyKey ?? (string) Str::uuid(), [
            'kind' => 'hold', 'from' => $from->id, 'to' => $to->id, 'amount' => $minor, 'code' => $code, 'timeout' => $timeoutSeconds,
        ], [
            'debit_account_id' => $from->id,
            'credit_account_id' => $to->id,
            'amount' => $minor,
            'currency' => $from->currency,
            'ledger' => $from->ledger,
            'tb_code' => $code,
            'timeout_seconds' => $timeoutSeconds,
            'expires_at' => $timeoutSeconds > 0 ? Carbon::now()->addSeconds($timeoutSeconds) : null,
            'reference' => $reference,
            'metadata' => $metadata ?: null,
        ]);

        return $this->writer->submit($hold, new TransferSpec(
            id: $hold->tb_id,
            debitAccountId: $from->tb_id,
            creditAccountId: $to->tb_id,
            amount: $minor,
            ledger: $hold->ledger,
            code: $code,
            flags: Flags::TRANSFER_PENDING,
            timeout: $timeoutSeconds,
        ), fn () => ['status' => HoldStatus::ACTIVE]);
    }

    /**
     * Post the hold, moving $amount (default: all of it) to the payee. Any
     * remainder goes back to the payer. A hold can be settled only once.
     */
    public function capture(LedgerHold|int $hold, ?int $amount = null, ?string $idempotencyKey = null): LedgerTransfer
    {
        $hold = $this->load($hold);
        $amount ??= $hold->amount;

        if ($amount <= 0 || $amount > $hold->amount) {
            throw new InvalidLedgerOperation("Capture amount must be between 1 and {$hold->amount}.");
        }

        return $this->settle($hold, TransferKind::HOLD_POST, $amount, $idempotencyKey ?? "hold:{$hold->id}:post");
    }

    /**
     * Void the hold and return the funds to the payer.
     */
    public function release(LedgerHold|int $hold, ?string $idempotencyKey = null): LedgerTransfer
    {
        $hold = $this->load($hold);

        return $this->settle($hold, TransferKind::HOLD_VOID, $hold->amount, $idempotencyKey ?? "hold:{$hold->id}:void");
    }

    /**
     * Settle local records for holds whose timeout has passed. TigerBeetle
     * already returned the funds; this voids each one, which TB answers with
     * PENDING_TRANSFER_EXPIRED, and marks it expired.
     *
     * @return int holds swept
     */
    public function sweepExpired(int $limit = 1000): int
    {
        $due = LedgerHold::query()
            ->where('status', HoldStatus::ACTIVE)
            ->where('expires_at', '<=', Carbon::now())
            ->limit($limit)
            ->get();

        foreach ($due as $hold) {
            try {
                $this->release($hold);
            } catch (TransferRejected) {
                // settle() has already recorded the outcome on the hold.
            }
        }

        return $due->count();
    }

    private function settle(LedgerHold $hold, TransferKind $kind, int $amount, string $key): LedgerTransfer
    {
        $isPost = $kind === TransferKind::HOLD_POST;

        // Retrying a settlement that already went through is fine; starting a
        // new one on a hold that is not active is not.
        if ($hold->status !== HoldStatus::ACTIVE && ! LedgerTransfer::query()->where('idempotency_key', $key)->exists()) {
            throw new InvalidLedgerOperation("Hold {$hold->id} is {$hold->status->value}, not active.");
        }

        $row = $this->writer->claim(LedgerTransfer::class, $key, [
            'kind' => $kind->value, 'hold' => $hold->id, 'amount' => $amount,
        ], [
            'kind' => $kind,
            'hold_id' => $hold->id,
            'debit_account_id' => $hold->debit_account_id,
            'credit_account_id' => $hold->credit_account_id,
            'amount' => $amount,
            'currency' => $hold->currency,
            'ledger' => $hold->ledger,
            'tb_code' => $hold->tb_code,
        ]);

        try {
            $row = $this->writer->submit($row, new TransferSpec(
                id: $row->tb_id,
                debitAccountId: '0',
                creditAccountId: '0',
                amount: $isPost ? $amount : 0,
                ledger: $hold->ledger,
                code: $hold->tb_code,
                flags: $isPost ? Flags::TRANSFER_POST_PENDING : Flags::TRANSFER_VOID_PENDING,
                pendingId: $hold->tb_id,
            ), TransferService::posted(...));
        } catch (TransferRejected $e) {
            $status = match ($e->result) {
                CreateTransferResult::PENDING_TRANSFER_EXPIRED => HoldStatus::EXPIRED,
                CreateTransferResult::PENDING_TRANSFER_ALREADY_VOIDED => HoldStatus::VOIDED,
                CreateTransferResult::PENDING_TRANSFER_ALREADY_POSTED => HoldStatus::POSTED,
                default => null,
            };
            if ($status !== null && $hold->status === HoldStatus::ACTIVE) {
                $hold->update(['status' => $status]);
            }

            throw $e;
        }

        if ($hold->status === HoldStatus::ACTIVE) {
            $hold->update($isPost
                ? ['status' => HoldStatus::POSTED, 'posted_amount' => $amount]
                : ['status' => HoldStatus::VOIDED]);
        }

        return $row;
    }

    private function load(LedgerHold|int $hold): LedgerHold
    {
        return $hold instanceof LedgerHold ? $hold->refresh() : LedgerHold::query()->findOrFail($hold);
    }
}
