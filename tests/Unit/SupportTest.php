<?php

use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Enums\CreateTransferResult;
use LedgerBeetle\Laravel\Gateways\Data\AccountSpec;
use LedgerBeetle\Laravel\Gateways\Data\TransferSpec;
use LedgerBeetle\Laravel\Gateways\InMemoryGateway;
use LedgerBeetle\Laravel\Support\Balance;
use LedgerBeetle\Laravel\Support\Flags;
use LedgerBeetle\Laravel\Support\Id;
use LedgerBeetle\Laravel\Support\Money;
use LedgerBeetle\Laravel\Support\U128;

it('parses decimal amounts without floats', function (string $input, string $currency, int $minor) {
    expect(Money::parse($input, $currency)->minor)->toBe($minor);
})->with([
    ['12.34', 'USD', 1234],
    ['0.1', 'USD', 10],
    ['12.340', 'USD', 1234],
    ['-5', 'USD', -500],
    ['1500', 'JPY', 1500],
    ['92233720368547758.07', 'USD', PHP_INT_MAX],
]);

it('refuses amounts it would have to round', function () {
    Money::parse('1.005', 'USD');
})->throws(InvalidArgumentException::class);

it('formats minor units', function () {
    expect((string) Money::of(5, 'USD'))->toBe('0.05 USD')
        ->and(Money::of(-123456, 'USD')->toDecimal())->toBe('-1234.56')
        ->and(Money::of(1500, 'JPY')->toDecimal())->toBe('1500');
});

it('narrows u128 strings to ints only when they fit', function () {
    expect(U128::toInt('42'))->toBe(42)
        ->and(U128::toInt((string) PHP_INT_MAX))->toBe(PHP_INT_MAX)
        ->and(fn () => U128::toInt('9223372036854775808'))->toThrow(OverflowException::class);
});

it('generates time-ordered 128-bit ids', function () {
    $a = Id::generate();
    usleep(2000);
    $b = Id::generate();

    expect(strlen($b))->toBeGreaterThan(30)
        ->and(bccomp($b, $a))->toBe(1);
});

it('signs balances from the normal side', function () {
    $wallet = Balance::fromTotals('USD', debitNormal: false, dp: 30, dP: 20, cp: 0, cP: 100);
    $cash = Balance::fromTotals('USD', debitNormal: true, dp: 0, dP: 100, cp: 10, cP: 40);

    expect([$wallet->posted, $wallet->available])->toBe([80, 50])
        ->and([$cash->posted, $cash->available])->toBe([60, 50]);
});

it('maps account types to overdraft flags', function () {
    expect(AccountType::LIABILITY->overdraftFlag())->toBe(Flags::ACCOUNT_DEBITS_MUST_NOT_EXCEED_CREDITS)
        ->and(AccountType::ASSET->overdraftFlag())->toBe(Flags::ACCOUNT_CREDITS_MUST_NOT_EXCEED_DEBITS);
});

it('fails a whole linked chain in the fake like TigerBeetle does', function () {
    $tb = new InMemoryGateway;
    $tb->createAccounts([new AccountSpec('1', 1, 1), new AccountSpec('2', 1, 1)]);

    $results = $tb->createTransfers([
        new TransferSpec('10', '1', '2', 5, 1, 1, Flags::TRANSFER_LINKED),
        new TransferSpec('11', '1', '9', 5, 1, 1),
        new TransferSpec('12', '1', '2', 5, 1, 1, Flags::TRANSFER_LINKED),
    ]);

    expect(array_map(fn ($r) => $r->result, $results))->toBe([
        CreateTransferResult::LINKED_EVENT_FAILED,
        CreateTransferResult::CREDIT_ACCOUNT_NOT_FOUND,
        CreateTransferResult::LINKED_EVENT_CHAIN_OPEN,
    ])->and($tb->lookupAccounts(['1'])['1']->debitsPosted)->toBe(0)
        // A transient failure burns the id.
        ->and($tb->createTransfers([new TransferSpec('11', '1', '2', 5, 1, 1)])[0]->result)
        ->toBe(CreateTransferResult::ID_ALREADY_FAILED);
});

it('treats EXISTS inside a linked chain as a failure, like TigerBeetle', function () {
    $tb = new InMemoryGateway;
    $tb->createAccounts([new AccountSpec('1', 1, 1), new AccountSpec('2', 1, 1), new AccountSpec('3', 1, 1)]);
    $chain = [
        new TransferSpec('10', '1', '2', 5, 1, 1, Flags::TRANSFER_LINKED),
        new TransferSpec('11', '1', '3', 5, 1, 1),
    ];
    $tb->createTransfers($chain);

    expect(array_map(fn ($r) => $r->result, $tb->createTransfers($chain)))->toBe([
        CreateTransferResult::EXISTS,
        CreateTransferResult::LINKED_EVENT_FAILED,
    ])->and($tb->lookupAccounts(['1'])['1']->debitsPosted)->toBe(10);
});
