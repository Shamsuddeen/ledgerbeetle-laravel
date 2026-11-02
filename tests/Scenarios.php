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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use LedgerBeetle\Laravel\Events\TransferBatchPosted;
use LedgerBeetle\Laravel\Models\LedgerConversion;
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

        // Crashed after the legs posted, before the conversion was marked.
        Ledger::createAccount('BOB.EUR', 'Bob EUR wallet', AccountType::LIABILITY, 'EUR');
        $stuck = Ledger::convert('ALICE', 'BOB.EUR', 10_00);
        $stuck->update(['status' => TransferStatus::PENDING]);

        $this->artisan('ledger:reconcile', ['--age' => 0])->assertFailed();
        $this->artisan('ledger:reconcile', ['--age' => 0, '--fix' => true])->assertSuccessful();

        expect($orphan->refresh()->status)->toBe(TransferStatus::FAILED)
            ->and($stuck->refresh()->status)->toBe(TransferStatus::POSTED)
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

    it('converts across ledgers atomically, with a fee', function () {
        Ledger::createAccount('BOB.EUR', 'Bob EUR wallet', AccountType::LIABILITY, 'EUR');

        $conversion = Ledger::convert('ALICE', 'BOB.EUR', Money::parse('50.00', 'USD'),
            idempotencyKey: 'fx-1', fee: 1_00, feeAccount: 'FEES');

        expect($conversion->status)->toBe(TransferStatus::POSTED)
            ->and($conversion->rate)->toBe('0.92')
            ->and($conversion->target_amount)->toBe(46_00)
            ->and($conversion->legs)->toHaveCount(3)
            ->and(Ledger::balance('ALICE')->available)->toBe(49_00)
            ->and(Ledger::balance('BOB.EUR')->posted)->toBe(46_00)
            ->and(Ledger::balance('FEES')->posted)->toBe(1_00)
            ->and(Ledger::balance('FX.POSITION.USD')->posted)->toBe(50_00)
            ->and(Ledger::balance('FX.POSITION.EUR')->posted)->toBe(-46_00)
            // Replays return the original; a different explicit rate is a different request.
            ->and(Ledger::convert('ALICE', 'BOB.EUR', 50_00, idempotencyKey: 'fx-1', fee: 1_00, feeAccount: 'FEES')->is($conversion))->toBeTrue()
            ->and(fn () => Ledger::convert('ALICE', 'BOB.EUR', 50_00, '0.5', 'fx-1', 1_00, 'FEES'))->toThrow(IdempotencyConflict::class)
            // Legs only move as a whole conversion.
            ->and(fn () => Ledger::reverse($conversion->legs[1]))->toThrow(InvalidLedgerOperation::class);
    });

    it('fails a conversion as a whole when the payer is short', function () {
        Ledger::createAccount('BOB.EUR', 'Bob EUR wallet', AccountType::LIABILITY, 'EUR');

        expect(fn () => Ledger::convert('ALICE', 'BOB.EUR', 200_00, idempotencyKey: 'fx-big'))->toThrow(InsufficientFunds::class);

        $conversion = LedgerConversion::query()->where('idempotency_key', 'fx-big')->sole();
        expect($conversion->status)->toBe(TransferStatus::FAILED)
            ->and($conversion->failure_reason)->toBe('EXCEEDS_CREDITS')
            ->and(Ledger::balance('BOB.EUR')->posted)->toBe(0)
            ->and(Ledger::balance('FX.POSITION.EUR')->posted)->toBe(0)
            ->and(fn () => Ledger::convert('ALICE', 'SHOP', 1_00))->toThrow(InvalidLedgerOperation::class);
    });

    it('reverses a conversion at its original rate, once', function () {
        Ledger::createAccount('BOB.EUR', 'Bob EUR wallet', AccountType::LIABILITY, 'EUR');
        $conversion = Ledger::convert('ALICE', 'BOB.EUR', 50_00, fee: 1_00, feeAccount: 'FEES');

        $reversal = Ledger::reverseConversion($conversion);

        expect($reversal->reverses_id)->toBe($conversion->id)
            ->and($reversal->source_currency)->toBe('EUR')
            ->and(Ledger::balance('ALICE')->available)->toBe(100_00)
            ->and(Ledger::balance('BOB.EUR')->posted)->toBe(0)
            ->and(Ledger::balance('FEES')->posted)->toBe(0)
            ->and(Ledger::balance('FX.POSITION.USD')->posted)->toBe(0)
            ->and(Ledger::reverseConversion($conversion)->is($reversal))->toBeTrue()
            ->and(fn () => Ledger::reverseConversion($conversion, 'another-key'))->toThrow(InvalidLedgerOperation::class)
            ->and(fn () => Ledger::reverseConversion($reversal))->toThrow(InvalidLedgerOperation::class);
    });

    it('rounds converted amounts down across currency scales', function () {
        $fx = app(\LedgerBeetle\Laravel\Services\ConversionService::class);

        expect($fx->targetAmount(1_01, '150.5', 'USD', 'JPY'))->toBe(152)      // 152.005 yen
            ->and($fx->targetAmount(152, '0.0066445', 'JPY', 'USD'))->toBe(1_00) // $1.00996
            ->and(fn () => $fx->targetAmount(1, '0.001', 'USD', 'EUR'))->toThrow(InvalidLedgerOperation::class)
            ->and(fn () => $fx->targetAmount(1_00, '-1', 'USD', 'EUR'))->toThrow(InvalidLedgerOperation::class);
    });

    it('answers balances as of a past moment', function () {
        $this->passSeconds(1);
        $afterFunding = Carbon::now();
        $this->passSeconds(1);
        Ledger::transfer('ALICE', 'SHOP', 30_00);

        $history = Ledger::balanceHistory('ALICE');

        expect(Ledger::balanceAt('ALICE', $afterFunding)->posted)->toBe(100_00)
            ->and(Ledger::balanceAt('ALICE', Carbon::now()->subYear())->posted)->toBe(0)
            ->and(Ledger::balance('ALICE')->posted)->toBe(70_00)
            ->and(array_map(fn ($s) => $s->balance->posted, $history))->toBe([70_00, 100_00])
            ->and($history[1]->at->lessThan($afterFunding))->toBeTrue()
            ->and(Ledger::balanceHistory('ALICE', from: $afterFunding))->toHaveCount(1);

        Ledger::createAccount('NOHIST', 'No history', AccountType::LIABILITY, history: false);
        expect(fn () => Ledger::balanceAt('NOHIST', Carbon::now()))->toThrow(InvalidLedgerOperation::class);
    });

    it('posts independent transfers in one batch', function () {
        Ledger::transfer('ALICE', 'SHOP', 5_00, 'earlier');
        $items = [
            ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => 10_00, 'idempotency_key' => 'b-1'],
            ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => 1_000_00, 'idempotency_key' => 'b-2'],
            ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => 5_00, 'idempotency_key' => 'earlier'],
            ['from' => 'NOPE', 'to' => 'SHOP', 'amount' => 1_00, 'idempotency_key' => 'b-3'],
        ];

        $results = Ledger::transferBatch($items);

        expect($results['b-1'])->toBeInstanceOf(LedgerTransfer::class)
            ->and($results['b-2'])->toBeInstanceOf(InsufficientFunds::class)
            ->and($results['earlier']->status)->toBe(TransferStatus::POSTED)
            ->and($results['b-3'])->toBeInstanceOf(\LedgerBeetle\Laravel\Exceptions\AccountNotFound::class)
            ->and(Ledger::balance('SHOP')->posted)->toBe(15_00);

        // The whole batch can be replayed safely.
        $again = Ledger::transferBatch($items);
        expect($again['b-1']->is($results['b-1']))->toBeTrue()
            ->and($again['b-2'])->toBeInstanceOf(InsufficientFunds::class)
            ->and(Ledger::balance('SHOP')->posted)->toBe(15_00);
    });

    it('posts queued batches and reports the outcome', function () {
        Event::fake([TransferBatchPosted::class]);

        $batchId = Ledger::queueBatch([
            ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => 2_00, 'idempotency_key' => 'q-1'],
            ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => 999_00, 'idempotency_key' => 'q-2'],
        ]);

        Event::assertDispatched(TransferBatchPosted::class, fn (TransferBatchPosted $e) => $e->batchId === $batchId
            && $e->posted === ['q-1'] && $e->failed === ['q-2' => 'exceeds_credits']);
        expect(Ledger::balance('SHOP')->posted)->toBe(2_00);
    });

    it('serves the HTTP API', function () {
        $this->postJson('/ledger/transfers', ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => '12.50'])
            ->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');

        $headers = ['Idempotency-Key' => 'api-1'];
        $created = $this->postJson('/ledger/transfers', ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => '12.50'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.amount.minor', 12_50)
            ->assertJsonPath('data.amount.decimal', '12.50');

        $this->postJson('/ledger/transfers', ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => '12.50'], $headers)
            ->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->postJson('/ledger/transfers', ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => '13.00'], $headers)
            ->assertConflict()->assertJsonPath('error.code', 'idempotency_conflict');
        $this->postJson('/ledger/transfers', ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => '500'], ['Idempotency-Key' => 'api-2'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'insufficient_funds');
        $this->postJson('/ledger/transfers', ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => '1.005'], ['Idempotency-Key' => 'api-3'])
            ->assertJsonValidationErrors('amount');
        $this->getJson('/ledger/accounts/NOPE')->assertNotFound()->assertJsonPath('error.code', 'account_not_found');

        $this->getJson('/ledger/accounts/ALICE')
            ->assertOk()->assertJsonPath('data.code', 'ALICE')->assertJsonPath('balance.available', 87_50);
        // Funding, api-1, and the rejected api-2 (failed attempts are listed too).
        $this->getJson('/ledger/accounts/ALICE/transfers')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.status', 'failed');
        $this->getJson('/ledger/accounts/ALICE/history')->assertOk()->assertJsonPath('data.0.posted', 87_50);

        $this->postJson("/ledger/transfers/{$created->json('data.id')}/reversals", ['amount' => '2.50'], ['Idempotency-Key' => 'api-r'])
            ->assertCreated()->assertJsonPath('data.kind', 'reversal');

        $hold = $this->postJson('/ledger/holds', ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => '20'], ['Idempotency-Key' => 'api-h'])
            ->assertCreated()->assertJsonPath('data.status', 'active');
        $this->postJson("/ledger/holds/{$hold->json('data.id')}/capture", ['amount' => '5'])
            ->assertCreated()->assertJsonPath('data.kind', 'hold_post');
        $this->getJson("/ledger/holds/{$hold->json('data.id')}")->assertJsonPath('data.status', 'posted');

        $this->postJson('/ledger/accounts', ['code' => 'BOB.EUR', 'name' => 'Bob', 'type' => 'liability', 'currency' => 'EUR'])
            ->assertCreated();
        $this->postJson('/ledger/conversions', ['from' => 'ALICE', 'to' => 'BOB.EUR', 'amount' => '10'], ['Idempotency-Key' => 'api-fx'])
            ->assertCreated()->assertJsonPath('data.target.decimal', '9.20');

        $this->postJson('/ledger/transfer-batches', ['items' => [
            ['from' => 'ALICE', 'to' => 'SHOP', 'amount' => '1', 'idempotency_key' => 'api-b1'],
        ]])->assertAccepted()->assertJsonPath('data.idempotency_keys', ['api-b1']);

        // 87.50 + 2.50 reversed - 5 captured - 10 converted - 1 batched
        expect(Ledger::balance('ALICE')->available)->toBe(74_00);
    });
}
