<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    |
    | "tigerbeetle" talks to a real cluster through PHP FFI and the native
    | shim built by bin/build-native.sh.
    | "memory" is an in-process fake for tests and local development; it keeps
    | nothing between requests.
    |
    */

    'gateway' => env('LEDGER_GATEWAY', 'tigerbeetle'),

    'tigerbeetle' => [
        'cluster_id' => env('TB_CLUSTER_ID', '0'),
        'addresses' => array_filter(explode(',', env('TB_ADDRESSES', '127.0.0.1:3000'))),
        'timeout_seconds' => (float) env('TB_TIMEOUT', 5),
        // Absolute path to libledgerbeetle_tb.so/.dylib. Null uses the one
        // bin/build-native.sh puts in this package's resources/lib/{platform}/.
        'library_path' => env('TB_CLIENT_LIB'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ledgers
    |--------------------------------------------------------------------------
    |
    | TigerBeetle only moves money between accounts on the same ledger, so
    | each currency gets its own. Ids default to the ISO 4217 numeric code.
    | "scale" is the number of minor-unit digits.
    |
    | Never change a ledger id once accounts exist on it.
    |
    */

    'ledgers' => [
        'USD' => ['ledger' => 840, 'scale' => 2],
        'EUR' => ['ledger' => 978, 'scale' => 2],
        'GBP' => ['ledger' => 826, 'scale' => 2],
        'JPY' => ['ledger' => 392, 'scale' => 0],
        'NGN' => ['ledger' => 566, 'scale' => 2],
        'KES' => ['ledger' => 404, 'scale' => 2],
        'GHS' => ['ledger' => 936, 'scale' => 2],
    ],

    'default_currency' => env('LEDGER_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Overdraft guards
    |--------------------------------------------------------------------------
    |
    | When true for an account type, new accounts of that type get the TB flag
    | that stops their balance going below zero on their normal side. TB
    | enforces this atomically, so there is no check-then-write race.
    | Override per account with the $preventOverdraft argument.
    |
    */

    'prevent_overdraft' => [
        'asset' => false,
        'liability' => true,
        'equity' => false,
        'revenue' => false,
        'expense' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency conversion
    |--------------------------------------------------------------------------
    |
    | Rates used by the default ExchangeRates binding, as decimal strings of
    | "TO units per FROM unit". The inverse pair is derived if missing. Bind
    | LedgerBeetle\Laravel\Contracts\ExchangeRates to use a live provider.
    |
    | prevent_short stops FX.POSITION.* accounts going negative, so a
    | conversion fails when the platform holds too little of the target
    | currency. Applies when the position account is first created.
    |
    */

    'fx' => [
        'rates' => [
            // 'USD/EUR' => '0.92',
        ],
        'prevent_short' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Balance history
    |--------------------------------------------------------------------------
    |
    | New accounts get TigerBeetle's history flag, which records the balance
    | after every transfer and enables Ledger::balanceAt() and
    | balanceHistory(). It cannot be added to an existing account.
    |
    */

    'balance_history' => true,

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    |
    | Off by default. When enabled, routes are registered under "prefix" with
    | "middleware". Keep "api" (it provides route model binding) and put real
    | authentication and authorization in front of it: these endpoints move
    | money between any accounts they are given.
    |
    */

    'api' => [
        'enabled' => (bool) env('LEDGER_API', false),
        'prefix' => env('LEDGER_API_PREFIX', 'ledger'),
        'middleware' => ['api', 'auth'],
    ],

    // Where PostTransferBatch jobs run. Null means the app's default.
    'queue' => [
        'connection' => env('LEDGER_QUEUE_CONNECTION'),
        'name' => env('LEDGER_QUEUE', 'default'),
    ],

    // TB `code` stamped on transfers when the caller does not pass one.
    'default_transfer_code' => 1,
];
