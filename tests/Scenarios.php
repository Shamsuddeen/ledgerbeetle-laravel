<?php

/*
 * Ledger behaviour shared by the Feature suite (in-memory gateway) and the
 * Integration suite (real TigerBeetle). Each file calls ledgerScenarios().
 */

use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Enums\CreateTransferResult;
use LedgerBeetle\Laravel\Enums\HoldStatus;
use LedgerBeetle\Laravel\Enums\TransferKind;
use LedgerBeetle\Laravel\Enums\TransferStatus;
use LedgerBeetle\Laravel\Exceptions\IdempotencyConflict;
use LedgerBeetle\Laravel\Exceptions\InsufficientFunds;
use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;
use LedgerBeetle\Laravel\Exceptions\TransferRejected;
use LedgerBeetle\Laravel\Facades\Ledger;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Services\TransferWriter;
use LedgerBeetle\Laravel\Support\Money;

function ledgerScenarios(): void
{
    beforeEach(function () {
        // Bank cash (asset, unguarded) funds customer wallets (liabilities,
        // guarded against overdraft by default).
        Ledger::createAccount('BANK', 'Bank', AccountType::ASSET);
        Ledger::createAccount('ALICE', 'Alice wallet', AccountType::LIABILITY);
        Ledger::createAccount('SHOP', 'Shop wallet', AccountType::LIABILITY);
        Ledger::createAccount('FEES', 'Fee revenue', AccountType::REVENUE);
        Ledger::transfer('BANK', 'ALICE', 100_00, 'fund-alice');
    });

    it('creates accounts idempotently by code', function () {
        $again = Ledger::createAccount('ALICE', 'Alice wallet', AccountType::LIABILITY);

        expect($again->is(Ledger::account('ALICE')))->toBeTrue()
            ->and(fn () => Ledger::createAccount('ALICE', 'Alice', AccountType::ASSET))
            ->toThrow(InvalidLedgerOperation::class);
    });

    it('moves money and reports balances from TigerBeetle', function () {
        $transfer = Ledger::transfer('ALICE', 'SHOP', Money::parse('25.50', 'USD'), reference: 'order-1');

        expect($transfer->status)->toBe(TransferStatus::POSTED)
            ->and($transfer->tb_timestamp)->toBeGreaterThan(0)
            ->and(Ledger::balance('ALICE')->available)->toBe(74_50)
            ->and(Ledger::balance('SHOP')->posted)->toBe(25_50)
            ->and(Ledger::balance('BANK')->posted)->toBe(100_00);
    });

    it('lets TigerBeetle reject an overdraft and remembers the failure', function () {
        expect(fn () => Ledger::transfer('ALICE', 'SHOP', 100_01, 'too-much'))->toThrow(InsufficientFunds::class);

        $row = LedgerTransfer::query()->where('idempotency_key', 'too-much')->sole();
        expect($row->status)->toBe(TransferStatus::FAILED)
            ->and($row->failure_reason)->toBe('EXCEEDS_CREDITS')
            ->and(Ledger::balance('ALICE')->available)->toBe(100_00)
            // Same key, same answer, even though the funds would now suffice.
            ->and(fn () => Ledger::transfer('ALICE', 'SHOP', 100_01, 'too-much'))->toThrow(InsufficientFunds::class);
    });

    it('moves money once per idempotency key', function () {
        $first = Ledger::transfer('ALICE', 'SHOP', 10_00, 'pay-1');
        $second = Ledger::transfer('ALICE', 'SHOP', 10_00, 'pay-1');

        expect($second->is($first))->toBeTrue()
            ->and(Ledger::balance('SHOP')->posted)->toBe(10_00)
            ->and(fn () => Ledger::transfer('ALICE', 'SHOP', 11_00, 'pay-1'))->toThrow(IdempotencyConflict::class);
    });

    it('resends a transfer left pending by a crash without double posting', function () {
        $posted = Ledger::transfer('ALICE', 'SHOP', 7_00, 'crash-after-tb');
        // Pretend we crashed after TigerBeetle accepted it but before we recorded that.
        $posted->update(['status' => TransferStatus::PENDING, 'tb_timestamp' => null]);

        $retried = Ledger::transfer('ALICE', 'SHOP', 7_00, 'crash-after-tb');

        expect($retried->status)->toBe(TransferStatus::POSTED)
            ->and($retried->tb_timestamp)->toBeGreaterThan(0)
            ->and(Ledger::balance('SHOP')->posted)->toBe(7_00);

        // Crashed before TigerBeetle saw it: the retry sends it for the first time.
        $alice = Ledger::account('ALICE');
        $shop = Ledger::account('SHOP');
        app(TransferWriter::class)->claim(LedgerTransfer::class, 'crash-before-tb',
            ['kind' => 'transfer', 'from' => $alice->id, 'to' => $shop->id, 'amount' => 3_00, 'code' => 1],
            ['kind' => TransferKind::TRANSFER, 'debit_account_id' => $alice->id, 'credit_account_id' => $shop->id,
                'amount' => 3_00, 'currency' => 'USD', 'ledger' => $alice->ledger, 'tb_code' => 1]);

        expect(Ledger::transfer('ALICE', 'SHOP', 3_00, 'crash-before-tb')->status)->toBe(TransferStatus::POSTED)
            ->and(Ledger::balance('SHOP')->posted)->toBe(10_00);
    });

    it('refuses transfers across currencies or with bad amounts', function () {
        Ledger::createAccount('ALICE.EUR', 'Alice EUR', AccountType::LIABILITY, 'EUR');

        expect(fn () => Ledger::transfer('ALICE', 'ALICE.EUR', 1_00))->toThrow(InvalidLedgerOperation::class)
            ->and(fn () => Ledger::transfer('ALICE', 'SHOP', Money::of(1_00, 'EUR')))->toThrow(InvalidLedgerOperation::class)
            ->and(fn () => Ledger::transfer('ALICE', 'SHOP', 0))->toThrow(InvalidLedgerOperation::class)
            ->and(fn () => Ledger::transfer('ALICE', 'ALICE', 1_00))->toThrow(InvalidLedgerOperation::class);
    });

    it('holds funds and captures part of them', function () {
        $hold = Ledger::hold('ALICE', 'SHOP', 50_00, idempotencyKey: 'hotel');

        expect($hold->status)->toBe(HoldStatus::ACTIVE)
            ->and(Ledger::balance('ALICE')->available)->toBe(50_00)
            ->and(Ledger::balance('ALICE')->posted)->toBe(100_00)
            // A second hold can only use what is left.
            ->and(fn () => Ledger::hold('ALICE', 'SHOP', 50_01))->toThrow(InsufficientFunds::class);

        $capture = Ledger::capture($hold, 30_00);

        expect($capture->kind)->toBe(TransferKind::HOLD_POST)
            ->and($hold->refresh()->status)->toBe(HoldStatus::POSTED)
            ->and($hold->posted_amount)->toBe(30_00)
            ->and(Ledger::balance('ALICE')->available)->toBe(70_00)
            ->and(Ledger::balance('SHOP')->posted)->toBe(30_00)
            // Retrying the same capture is fine; settling again is not.
            ->and(Ledger::capture($hold, 30_00)->is($capture))->toBeTrue()
            ->and(fn () => Ledger::release($hold))->toThrow(InvalidLedgerOperation::class);
    });

    it('releases a hold back to the payer', function () {
        $hold = Ledger::hold('ALICE', 'SHOP', 40_00);
        Ledger::release($hold);

        expect($hold->refresh()->status)->toBe(HoldStatus::VOIDED)
            ->and(Ledger::balance('ALICE')->available)->toBe(100_00)
            ->and(Ledger::balance('SHOP')->posted)->toBe(0);
    });

    it('lets TigerBeetle expire a hold after its timeout', function () {
        $hold = Ledger::hold('ALICE', 'SHOP', 40_00, timeoutSeconds: 1);
        expect(Ledger::balance('ALICE')->available)->toBe(60_00);

        $this->passSeconds(3);

        expect(Ledger::balance('ALICE')->available)->toBe(100_00)
            ->and(app(\LedgerBeetle\Laravel\Services\HoldService::class)->sweepExpired())->toBe(1)
            ->and($hold->refresh()->status)->toBe(HoldStatus::EXPIRED)
            ->and(fn () => Ledger::capture($hold))->toThrow(InvalidLedgerOperation::class);
    });

    it('reverses in parts, never more than the original', function () {
        $payment = Ledger::transfer('ALICE', 'SHOP', 100_00);

        $first = Ledger::reverse($payment, 40_00, 'refund-1', 'damaged item');
        expect($first->kind)->toBe(TransferKind::REVERSAL)
            ->and($first->reverses_id)->toBe($payment->id)
            ->and(Ledger::balance('ALICE')->available)->toBe(40_00)
            ->and(Ledger::reverse($payment, 40_00, 'refund-1')->is($first))->toBeTrue();

        Ledger::reverse($payment, idempotencyKey: 'refund-2');

        expect($payment->refresh()->reversed_amount)->toBe(100_00)
            ->and(Ledger::balance('ALICE')->available)->toBe(100_00)
            ->and(Ledger::balance('SHOP')->posted)->toBe(0)
            ->and(fn () => Ledger::reverse($payment, 1))->toThrow(InvalidLedgerOperation::class)
            ->and(fn () => Ledger::reverse($first))->toThrow(InvalidLedgerOperation::class);

        $links = collect(Ledger::history('SHOP'))->pluck('userData128')->filter(fn ($v) => $v !== '0')->unique()->values()->all();
        expect($links)->toBe([$payment->tb_id]);
    });

    it('gives the reservation back when the payee already spent the money', function () {
        $payment = Ledger::transfer('ALICE', 'SHOP', 60_00);
        Ledger::transfer('SHOP', 'BANK', 50_00);

        expect(fn () => Ledger::reverse($payment))->toThrow(InsufficientFunds::class)
            ->and($payment->refresh()->reversed_amount)->toBe(0)
            ->and(Ledger::reverse($payment, 10_00)->status)->toBe(TransferStatus::POSTED);
    });

    it('posts linked legs all together or not at all', function () {
        $legs = fn (int $fee) => [
            ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => 90_00],
            ['from' => 'ALICE', 'to' => 'FEES', 'amount' => $fee],
        ];

        try {
            Ledger::transferMany($legs(20_00), 'checkout-1');
            $this->fail('Expected the second leg to overdraw Alice.');
        } catch (TransferRejected $e) {
            expect($e->result)->toBe(CreateTransferResult::EXCEEDS_CREDITS);
        }

        expect(Ledger::balance('SHOP')->posted)->toBe(0)
            ->and(LedgerTransfer::query()->where('status', TransferStatus::FAILED)->count())->toBe(2);

        $rows = Ledger::transferMany($legs(5_00), 'checkout-2');
        $rows[0]->update(['status' => TransferStatus::PENDING]);
        $rows[1]->update(['status' => TransferStatus::PENDING]);
        Ledger::transferMany($legs(5_00), 'checkout-2');

        expect(Ledger::balance('SHOP')->posted)->toBe(90_00)
            ->and(Ledger::balance('FEES')->posted)->toBe(5_00)
            ->and(Ledger::balance('ALICE')->available)->toBe(5_00);
    });

    it('reconciles rows that never reached TigerBeetle', function () {
        $alice = Ledger::account('ALICE');
        $shop = Ledger::account('SHOP');
        $orphan = app(TransferWriter::class)->claim(LedgerTransfer::class, 'orphan', ['x' => 1], [
            'kind' => TransferKind::TRANSFER, 'debit_account_id' => $alice->id, 'credit_account_id' => $shop->id,
            'amount' => 1_00, 'currency' => 'USD', 'ledger' => $alice->ledger, 'tb_code' => 1,
        ]);
        $ghost = $alice->replicate()->fill(['code' => 'GHOST', 'tb_id' => \LedgerBeetle\Laravel\Support\Id::generate()]);
        $ghost->save();

        $this->artisan('ledger:reconcile', ['--age' => 0])->assertFailed();
        $this->artisan('ledger:reconcile', ['--age' => 0, '--fix' => true])->assertSuccessful();

        expect($orphan->refresh()->status)->toBe(TransferStatus::FAILED)
            ->and(Ledger::balance('GHOST')->posted)->toBe(0);

        $this->artisan('ledger:reconcile', ['--age' => 0, '--deep' => true])
            ->expectsOutputToContain('in sync')
            ->assertSuccessful();
    });

    it('seeds a chart of accounts', function () {
        $this->artisan('ledger:seed-accounts', ['--currency' => 'NGN'])->assertSuccessful();
        $this->artisan('ledger:seed-accounts', ['--currency' => 'NGN'])->assertSuccessful();

        $operating = Ledger::account('ASSET.CASH.OPERATING.NGN');
        expect($operating->parent->code)->toBe('ASSET.CASH.NGN')
            ->and($operating->ledger)->toBe(566);
    });
}
