<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Services;

use Illuminate\Support\Str;
use LedgerBeetle\Laravel\Contracts\ExchangeRates;
use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Enums\TransferKind;
use LedgerBeetle\Laravel\Enums\TransferStatus;
use LedgerBeetle\Laravel\Exceptions\ClusterUnavailable;
use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;
use LedgerBeetle\Laravel\Exceptions\LedgerException;
use LedgerBeetle\Laravel\Exceptions\TransferRejected;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Models\LedgerConversion;
use LedgerBeetle\Laravel\Support\Ledgers;
use LedgerBeetle\Laravel\Support\Money;
use LedgerBeetle\Laravel\Support\U128;

/**
 * Currency conversions. TigerBeetle only moves money within one ledger, so a
 * conversion is a linked chain across two ledgers through the platform's FX
 * position accounts (FX.POSITION.{CUR}):
 *
 *   [fee]  payer  → fee account   (source currency, optional)
 *   leg 1  payer  → FX.POSITION.SRC  source amount
 *   leg 2  FX.POSITION.TGT → payee   target amount
 *
 * All legs post together or not at all. Position accounts are equity; a
 * negative balance means the platform is short that currency.
 */
final class ConversionService
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly TransferWriter $writer,
        private readonly AccountService $accounts,
        private readonly ExchangeRates $rates,
        private readonly Ledgers $ledgers,
        private readonly bool $preventShortPositions,
    ) {}

    /**
     * Convert $amount (in the payer's currency) into the payee's currency.
     *
     * The target amount is rounded down to the payee currency's minor unit.
     * A retry with the same key reuses the rate stored by the first attempt.
     *
     * @param  string|null  $rate  payee units per payer unit; null asks ExchangeRates
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
        $from = $this->accounts->resolve($from);
        $to = $this->accounts->resolve($to);
        $source = $this->minor($amount, $from->currency);
        $feeMinor = $this->minor($fee, $from->currency);
        $feeAccount = $feeAccount === null ? null : $this->accounts->resolve($feeAccount);
        $key = $idempotencyKey ?? (string) Str::uuid();

        if ($from->currency === $to->currency) {
            throw new InvalidLedgerOperation('Both accounts are in '.$from->currency.'; use transfer() instead.');
        }
        if ($source <= 0 || $feeMinor < 0) {
            throw new InvalidLedgerOperation('Conversion amount must be positive and the fee non-negative.');
        }
        if ($feeMinor > 0 && $feeAccount?->currency !== $from->currency) {
            throw new InvalidLedgerOperation("A conversion fee needs a fee account in {$from->currency}.");
        }

        $existing = LedgerConversion::query()->where('idempotency_key', $key)->first();
        $effectiveRate = $existing->rate ?? $rate ?? $this->rates->rate($from->currency, $to->currency);
        $target = $this->targetAmount($source, $effectiveRate, $from->currency, $to->currency);

        $conversion = $this->writer->claim(LedgerConversion::class, $key, [
            'kind' => 'conversion', 'from' => $from->id, 'to' => $to->id, 'amount' => $source,
            'fee' => $feeMinor, 'fee_account' => $feeAccount?->id, 'rate' => $rate,
        ], [
            'source_account_id' => $from->id,
            'target_account_id' => $to->id,
            'source_amount' => $source,
            'source_currency' => $from->currency,
            'target_amount' => $target,
            'target_currency' => $to->currency,
            'rate' => $effectiveRate,
            'fee_amount' => $feeMinor,
            'fee_account_id' => $feeAccount?->id,
            'reference' => $reference,
            'metadata' => $metadata ?: null,
        ], withTbId: false);

        $legs = [];
        if ($conversion->fee_amount > 0) {
            $legs[] = ['from' => $from, 'to' => $feeAccount, 'amount' => $conversion->fee_amount];
        }
        $legs[] = ['from' => $from, 'to' => $this->position($from->currency), 'amount' => $conversion->source_amount];
        $legs[] = ['from' => $this->position($to->currency), 'to' => $to, 'amount' => $conversion->target_amount];

        return $this->post($conversion, $legs);
    }

    /**
     * Undo a conversion at its original rate: every leg runs backwards,
     * including the fee. The platform carries any FX movement since. A
     * conversion can be reversed once.
     */
    public function reverse(LedgerConversion|int $conversion, ?string $idempotencyKey = null): LedgerConversion
    {
        $original = $conversion instanceof LedgerConversion ? $conversion->refresh() : LedgerConversion::query()->findOrFail($conversion);

        if ($original->status !== TransferStatus::POSTED || $original->reverses_id !== null) {
            throw new InvalidLedgerOperation("Conversion {$original->id} is not a posted conversion that can be reversed.");
        }

        $key = $idempotencyKey ?? "conversion:{$original->id}:reversal";
        $taken = LedgerConversion::query()->where('reverses_id', $original->id)->first();
        if ($taken !== null && $taken->idempotency_key !== $key) {
            throw new InvalidLedgerOperation("Conversion {$original->id} has already been reversed.");
        }

        $reversal = $this->writer->claim(LedgerConversion::class, $key, ['kind' => 'conversion_reversal', 'of' => $original->id], [
            'source_account_id' => $original->target_account_id,
            'target_account_id' => $original->source_account_id,
            'source_amount' => $original->target_amount,
            'source_currency' => $original->target_currency,
            'target_amount' => $original->source_amount,
            'target_currency' => $original->source_currency,
            'rate' => $original->rate,
            'fee_amount' => $original->fee_amount,
            'fee_account_id' => $original->fee_account_id,
            'reverses_id' => $original->id,
        ], withTbId: false);

        $legs = [
            ['from' => $original->targetAccount, 'to' => $this->position($original->target_currency), 'amount' => $original->target_amount],
            ['from' => $this->position($original->source_currency), 'to' => $original->sourceAccount, 'amount' => $original->source_amount],
        ];
        if ($original->fee_amount > 0) {
            $legs[] = ['from' => $original->feeAccount, 'to' => $original->sourceAccount, 'amount' => $original->fee_amount];
        }

        return $this->post($reversal, $legs);
    }

    /**
     * The platform's position account for a currency, created on first use.
     */
    public function position(string $currency): LedgerAccount
    {
        return $this->accounts->create(
            code: 'FX.POSITION.'.strtoupper($currency),
            name: 'FX position '.strtoupper($currency),
            type: AccountType::EQUITY,
            currency: $currency,
            preventOverdraft: $this->preventShortPositions,
        );
    }

    /**
     * floor(source × rate), shifted between the two currencies' scales.
     */
    public function targetAmount(int $source, string $rate, string $fromCurrency, string $toCurrency): int
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $rate) || bccomp($rate, '0', 18) <= 0) {
            throw new InvalidLedgerOperation("[{$rate}] is not a positive exchange rate.");
        }

        $shift = $this->ledgers->scaleFor($toCurrency) - $this->ledgers->scaleFor($fromCurrency);
        $value = bcmul((string) $source, $rate, 18);
        $value = $shift >= 0
            ? bcmul($value, bcpow('10', (string) $shift), 18)
            : bcdiv($value, bcpow('10', (string) -$shift), 18);
        $target = U128::toInt(bcdiv($value, '1', 0));

        if ($target <= 0) {
            throw new InvalidLedgerOperation('The converted amount rounds down to zero.');
        }

        return $target;
    }

    /**
     * @param  list<array{from: LedgerAccount, to: LedgerAccount, amount: int}>  $legs
     */
    private function post(LedgerConversion $conversion, array $legs): LedgerConversion
    {
        try {
            $this->transfers->transferMany($legs, "conversion:{$conversion->idempotency_key}",
                TransferKind::CONVERSION, ['conversion_id' => $conversion->id]);
        } catch (ClusterUnavailable $e) {
            throw $e; // Outcome unknown: stays pending for a retry or ledger:reconcile.
        } catch (LedgerException $e) {
            $conversion->update([
                'status' => TransferStatus::FAILED,
                'failure_reason' => $e instanceof TransferRejected ? $e->result->name : Str::limit($e->getMessage(), 77),
                // Free the one-reversal-per-conversion slot for a later attempt.
                'reverses_id' => null,
                'metadata' => $conversion->reverses_id === null ? $conversion->metadata
                    : ($conversion->metadata ?? []) + ['attempted_reversal_of' => $conversion->reverses_id],
            ]);

            throw $e;
        }

        if ($conversion->status !== TransferStatus::POSTED) {
            $conversion->update(['status' => TransferStatus::POSTED, 'failure_reason' => null]);
        }

        return $conversion;
    }

    private function minor(Money|int $amount, string $currency): int
    {
        if ($amount instanceof Money && $amount->currency !== $currency) {
            throw new InvalidLedgerOperation("Amount is in {$amount->currency} but the payer account is in {$currency}.");
        }

        return $amount instanceof Money ? $amount->minor : $amount;
    }
}
