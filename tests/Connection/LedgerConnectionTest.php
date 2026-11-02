<?php

use Illuminate\Support\Facades\DB;
use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Facades\Ledger;
use LedgerBeetle\Laravel\Models\LedgerTransfer;

it('keeps ledger rows when the app transaction rolls back, so a retry posts once', function () {
    Ledger::createAccount('BANK.USD', 'Bank', AccountType::ASSET, 'USD');
    Ledger::createAccount('WALLET.1', 'Wallet', AccountType::LIABILITY, 'USD');

    expect((new LedgerTransfer)->getConnectionName())->toBe('ledger');

    try {
        DB::transaction(function () {
            Ledger::transfer('BANK.USD', 'WALLET.1', 10_00, idempotencyKey: 'deposit-1');

            throw new RuntimeException('app failed after the ledger posted');
        });
    } catch (RuntimeException) {
    }

    $first = LedgerTransfer::query()->where('idempotency_key', 'deposit-1')->sole();
    expect($first->status->value)->toBe('posted');

    $retry = Ledger::transfer('BANK.USD', 'WALLET.1', 10_00, idempotencyKey: 'deposit-1');

    expect($retry->tb_id)->toBe($first->tb_id)
        ->and(Ledger::balance('WALLET.1')->posted)->toBe(10_00);
});

it('runs reversals on the ledger connection', function () {
    Ledger::createAccount('BANK.USD', 'Bank', AccountType::ASSET, 'USD');
    Ledger::createAccount('WALLET.1', 'Wallet', AccountType::LIABILITY, 'USD');
    $payment = Ledger::transfer('BANK.USD', 'WALLET.1', 10_00, idempotencyKey: 'deposit-1');

    Ledger::reverse($payment, 4_00, idempotencyKey: 'refund-1');

    expect(Ledger::balance('WALLET.1')->posted)->toBe(6_00);
});
