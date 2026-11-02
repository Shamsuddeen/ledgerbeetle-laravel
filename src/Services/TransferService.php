<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Services;

use Illuminate\Support\Str;
use LedgerBeetle\Laravel\Contracts\TigerBeetleGateway;
use LedgerBeetle\Laravel\Enums\CreateTransferResult;
use LedgerBeetle\Laravel\Enums\TransferKind;
use LedgerBeetle\Laravel\Enums\TransferStatus;
use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;
use LedgerBeetle\Laravel\Exceptions\TransferRejected;
use LedgerBeetle\Laravel\Gateways\Data\CreateOutcome;
use LedgerBeetle\Laravel\Gateways\Data\TransferSpec;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Support\Flags;
use LedgerBeetle\Laravel\Support\Money;

final class TransferService
{
    public function __construct(
        private readonly TigerBeetleGateway $gateway,
        private readonly TransferWriter $writer,
        private readonly AccountService $accounts,
        private readonly int $defaultCode,
    ) {}

    /**
     * Move money between two accounts in the same currency.
     *
     * Retrying with the same idempotency key returns the original transfer
     * (or rethrows its original failure) without moving money again.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws \LedgerBeetle\Laravel\Exceptions\InsufficientFunds
     * @throws TransferRejected
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
        $leg = $this->leg($from, $to, $amount, $code);
        $key = $idempotencyKey ?? (string) Str::uuid();

        $row = $this->writer->claim(LedgerTransfer::class, $key, $leg['fingerprint'], $leg['attributes'] + [
            'kind' => TransferKind::TRANSFER,
            'reference' => $reference,
            'metadata' => $metadata ?: null,
        ]);

        return $this->writer->submit($row, $this->spec($row, $leg['from'], $leg['to']), self::posted(...));
    }

    /**
     * Post several transfers atomically: all succeed or none do. Use it for
     * multi-leg postings such as a payment plus its fee.
     *
     * @param  list<array{from: LedgerAccount|string, to: LedgerAccount|string, amount: Money|int, reference?: string, code?: int}>  $legs
     * @return list<LedgerTransfer>
     */
    public function transferMany(array $legs, string $idempotencyKey): array
    {
        if ($legs === []) {
            throw new InvalidLedgerOperation('A linked posting needs at least one leg.');
        }

        $rows = $specs = [];

        foreach (array_values($legs) as $i => $input) {
            $leg = $this->leg($input['from'], $input['to'], $input['amount'], $input['code'] ?? null);
            $row = $this->writer->claim(LedgerTransfer::class, "{$idempotencyKey}#{$i}", $leg['fingerprint'] + ['legs' => count($legs)], $leg['attributes'] + [
                'kind' => TransferKind::TRANSFER,
                'reference' => $input['reference'] ?? null,
                'metadata' => ['linked_key' => $idempotencyKey, 'leg' => $i],
            ]);

            $rows[] = $row;
            $specs[] = $this->spec($row, $leg['from'], $leg['to'], $i < count($legs) - 1 ? Flags::TRANSFER_LINKED : 0);
        }

        if ($failed = collect($rows)->first(fn (LedgerTransfer $r) => $r->status === TransferStatus::FAILED)) {
            throw TransferRejected::fromRecorded($failed->failure_reason);
        }

        if (collect($rows)->every(fn (LedgerTransfer $r) => $r->status === TransferStatus::POSTED)) {
            return $rows;
        }

        // A linked chain cannot be resent blindly: TigerBeetle answers EXISTS
        // for the first leg and fails the rest. Ask whether it already landed.
        $landed = $this->gateway->lookupTransfers(array_map(fn (LedgerTransfer $r) => $r->tb_id, $rows));
        if (count(array_filter($landed)) === count($rows)) {
            foreach ($rows as $row) {
                $row->update(['status' => TransferStatus::POSTED, 'tb_timestamp' => $landed[$row->tb_id]->timestamp]);
            }

            return $rows;
        }

        $outcomes = $this->gateway->createTransfers($specs);

        if (collect($outcomes)->every(fn (CreateOutcome $o) => $o->ok())) {
            foreach ($rows as $i => $row) {
                $row->update(self::posted($outcomes[$i]));
            }

            return $rows;
        }

        // The leg that broke the chain; its siblings only report LINKED_EVENT_FAILED.
        $cause = collect($outcomes)->first(fn (CreateOutcome $o) => ! $o->ok() && $o->result !== CreateTransferResult::LINKED_EVENT_FAILED)->result
            ?? CreateTransferResult::LINKED_EVENT_FAILED;

        foreach ($rows as $row) {
            $row->update(['status' => TransferStatus::FAILED, 'failure_reason' => $cause->name]);
        }

        throw TransferRejected::for($cause);
    }

    /**
     * @return array<string, mixed>
     */
    public static function posted(CreateOutcome $outcome): array
    {
        return ['status' => TransferStatus::POSTED, 'tb_timestamp' => $outcome->timestamp];
    }

    /**
     * @return array{from: LedgerAccount, to: LedgerAccount, fingerprint: array<string, mixed>, attributes: array<string, mixed>}
     */
    private function leg(LedgerAccount|string $from, LedgerAccount|string $to, Money|int $amount, ?int $code): array
    {
        $from = $this->accounts->resolve($from);
        $to = $this->accounts->resolve($to);
        $code ??= $this->defaultCode;

        if ($from->is($to)) {
            throw new InvalidLedgerOperation('Cannot transfer from an account to itself.');
        }
        if ($from->currency !== $to->currency) {
            throw new InvalidLedgerOperation("Cannot transfer between {$from->currency} and {$to->currency} accounts directly.");
        }
        if ($amount instanceof Money && $amount->currency !== $from->currency) {
            throw new InvalidLedgerOperation("Amount is in {$amount->currency} but the accounts are in {$from->currency}.");
        }

        $minor = $amount instanceof Money ? $amount->minor : $amount;

        if ($minor <= 0) {
            throw new InvalidLedgerOperation('Transfer amount must be positive.');
        }

        return [
            'from' => $from,
            'to' => $to,
            'fingerprint' => ['kind' => 'transfer', 'from' => $from->id, 'to' => $to->id, 'amount' => $minor, 'code' => $code],
            'attributes' => [
                'debit_account_id' => $from->id,
                'credit_account_id' => $to->id,
                'amount' => $minor,
                'currency' => $from->currency,
                'ledger' => $from->ledger,
                'tb_code' => $code,
            ],
        ];
    }

    private function spec(LedgerTransfer $row, LedgerAccount $from, LedgerAccount $to, int $flags = 0): TransferSpec
    {
        return new TransferSpec(
            id: $row->tb_id,
            debitAccountId: $from->tb_id,
            creditAccountId: $to->tb_id,
            amount: $row->amount,
            ledger: $row->ledger,
            code: $row->tb_code,
            flags: $flags,
        );
    }
}
