# LedgerBeetle for Laravel

A double-entry ledger for Laravel with [TigerBeetle](https://tigerbeetle.com) as the source of truth.

- **TigerBeetle holds the money.** Balances are read from TigerBeetle, never summed from SQL. Your database keeps only the metadata: account codes and names, references, idempotency keys and status.
- **No overdraft races.** Overdraft protection is a TigerBeetle account flag, so it is enforced atomically inside the cluster instead of by a check-then-write in PHP.
- **Retries are safe.** Every write takes an idempotency key. Retrying with the same key never moves money twice, and a mismatched retry is rejected.
- **Holds** are TigerBeetle pending transfers. You can post all or part of them, void them, or let TigerBeetle expire them.
- **Reversals** are new, linked transfers, because posted transfers are immutable. Partial reversals can never add up to more than the original, even under concurrency.
- **Multi-leg postings** (for example a payment plus its fee) are atomic.

Requires PHP 8.2+, `ext-ffi`, `ext-bcmath` (`ext-gmp` is faster if you have it), Laravel 11–13, and TigerBeetle 0.17.x.

## Install

```bash
composer require ledgerbeetle/laravel
php artisan vendor:publish --tag=ledger-config
php artisan migrate
```

### Build the native client

PHP has no official TigerBeetle client. This package talks to TigerBeetle's official C client (`tb_client`) through PHP FFI, plus a small C shim ([native/ledgerbeetle_tb.c](native/ledgerbeetle_tb.c)) that makes its callback-based API synchronous.

```bash
vendor/ledgerbeetle/laravel/bin/build-native.sh 0.17.9
```

The script:

1. Fetches TigerBeetle's prebuilt static `libtb_client` and `tb_client.h` for your platform from the [tigerbeetle-go](https://github.com/tigerbeetle/tigerbeetle-go) release.
2. Checks both files against the git hashes GitHub reports for that tag.
3. Builds `resources/lib/<platform>/libledgerbeetle_tb.{so,dylib}`.

It needs a C compiler. It supports Linux and macOS on x86_64 and arm64. Build it for each platform you deploy to, for example in your Docker image.

FFI must be allowed to load the library. The CLI allows it by default. For PHP-FPM, set `ffi.enable=true` in php.ini.

### Configure

```dotenv
LEDGER_GATEWAY=tigerbeetle      # or "memory" for tests
TB_CLUSTER_ID=0
TB_ADDRESSES=10.0.0.1:3000,10.0.0.2:3000,10.0.0.3:3000
TB_TIMEOUT=5
# TB_CLIENT_LIB=/abs/path/libledgerbeetle_tb.so   # if not using the bundled build
```

TigerBeetle only moves money between accounts on the same ledger, so each currency is its own ledger. `config/ledger.php` maps currencies to ledger ids (ISO 4217 numeric codes by default) and decimal scale. **Never change a ledger id once accounts exist.**

## Use

```php
use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Facades\Ledger;
use LedgerBeetle\Laravel\Support\Money;

// Liabilities (e.g. customer wallets) are overdraft-protected by default.
Ledger::createAccount('BANK.USD', 'Bank', AccountType::ASSET, 'USD');
Ledger::createAccount('WALLET.42', 'Wallet #42', AccountType::LIABILITY, 'USD');
Ledger::createAccount('WALLET.77', 'Wallet #77', AccountType::LIABILITY, 'USD');

// Deposit: debit the bank, credit the wallet.
Ledger::transfer('BANK.USD', 'WALLET.42', Money::parse('100.00', 'USD'), idempotencyKey: 'deposit-9f2c');

// Payment. Throws InsufficientFunds if it would overdraw the wallet.
$payment = Ledger::transfer('WALLET.42', 'WALLET.77', 25_00, idempotencyKey: 'order-1001', reference: 'order-1001');

Ledger::balance('WALLET.42');   // Balance { posted: 7500, available: 7500, ... } in minor units

// Hold, then capture part of it. The rest returns to the payer.
$hold = Ledger::hold('WALLET.42', 'WALLET.77', 50_00, timeoutSeconds: 86400, idempotencyKey: 'hotel-7');
Ledger::capture($hold, 30_00);       // or Ledger::release($hold);

// Partial refund.
Ledger::reverse($payment, 10_00, idempotencyKey: 'refund-1001-a', reason: 'damaged item');

// Payment plus fee: both legs post or neither does.
Ledger::transferMany([
    ['from' => 'WALLET.42', 'to' => 'WALLET.77', 'amount' => 20_00],
    ['from' => 'WALLET.42', 'to' => 'REVENUE.FEES.USD', 'amount' => 50],
], idempotencyKey: 'checkout-55');
```

### Idempotency

Always pass an `idempotencyKey` derived from your own request, such as an order id or an `Idempotency-Key` header.

- **Same key, same parameters:** you get the original result back, or the original failure rethrown. Money moves at most once.
- **Same key, different parameters:** `IdempotencyConflict`.
- **`ClusterUnavailable`** means TigerBeetle did not answer in time. The transfer may or may not have been applied. Retry with the same key to find out.

Every write first records a `pending` row with a fresh TigerBeetle id, then submits it. If the process dies between those steps, a retry resends the same id, and TigerBeetle answers `EXISTS` instead of posting twice.

### Commands

| Command | What it does |
|---|---|
| `ledger:seed-accounts --currency=USD` | Starter chart of accounts (`ASSET.CASH.USD`, `REVENUE.FEES.USD`, …). Safe to rerun. |
| `ledger:sweep-holds` | Marks holds past their timeout as expired. Schedule it, e.g. every five minutes. |
| `ledger:reconcile [--fix] [--deep]` | Compares local rows with TigerBeetle: missing accounts, rows stuck in `pending`, and (with `--deep`) the amount of every posted transfer. |

## Testing your app

Set `LEDGER_GATEWAY=memory` to use `InMemoryGateway`, an in-process fake that copies the TigerBeetle rules this package relies on:

- idempotent ids
- linked chains
- balance-limit flags
- pending/post/void with timeouts that follow Carbon's clock
- tombstoned ids after transient failures

## Developing this package

```bash
composer install
composer test                       # unit + feature tests, in-memory gateway

docker compose up -d                # single-replica TigerBeetle 0.17.9 on :3000
bin/build-native.sh
TB_ADDRESSES=127.0.0.1:3000 composer test:integration   # same scenarios against the real cluster
```

The feature and integration suites run the same scenarios ([tests/Scenarios.php](tests/Scenarios.php)), so the fake is checked against real TigerBeetle.

## Not in this version

- **Cross-currency transfers.** These need linked legs through per-currency liquidity accounts.
- **Balance history and projections.** These need the `history` account flag and `get_account_balances`.
- **HTTP API and queued batch posting.**
