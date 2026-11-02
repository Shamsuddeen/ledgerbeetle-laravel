# LedgerBeetle for Laravel

A double-entry ledger for Laravel with [TigerBeetle](https://tigerbeetle.com) as the source of truth.

- **TigerBeetle holds the money.** Balances are read from TigerBeetle, never summed from SQL. Your database keeps only the metadata: account codes and names, references, idempotency keys and status.
- **No overdraft races.** Overdraft protection is a TigerBeetle account flag, so it is enforced atomically inside the cluster instead of by a check-then-write in PHP.
- **Retries are safe.** Every write takes an idempotency key. Retrying with the same key never moves money twice, and a mismatched retry is rejected.
- **Holds** are TigerBeetle pending transfers. You can post all or part of them, void them, or let TigerBeetle expire them.
- **Reversals** are new, linked transfers, because posted transfers are immutable. Partial reversals can never add up to more than the original, even under concurrency.
- **Multi-leg postings** (for example a payment plus its fee) are atomic.
- **Currency conversions** are one atomic linked posting across two ledgers. They go through the platform's FX position accounts and can be reversed as a whole at the original rate.
- **Balance history:** TigerBeetle records the balance after every transfer, so you can ask what an account held at any past moment.
- **Batch posting** sends independent transfers in one round trip, either inline or as a queued job.
- **HTTP API** (off by default) with required idempotency keys and JSON errors.

[![Tests](https://github.com/Shamsuddeen/ledgerbeetle-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/Shamsuddeen/ledgerbeetle-laravel/actions/workflows/tests.yml)

Requires PHP 8.2+, `ext-ffi`, `ext-bcmath` (`ext-gmp` is faster if you have it), Laravel 10–13, and TigerBeetle 0.17.x. Laravel 10 is end-of-life, so prefer 11 or newer for new apps.

## Install

```bash
composer require omacy/ledgerbeetle
php artisan vendor:publish --tag=ledger-config
php artisan migrate
```

### Build the native client

PHP has no official TigerBeetle client. This package talks to TigerBeetle's official C client (`tb_client`) through PHP FFI, plus a small C shim ([native/ledgerbeetle_tb.c](native/ledgerbeetle_tb.c)) that makes its callback-based API synchronous.

Composer wipes `vendor/` on every update, so build the libraries into a folder your app owns and point the package at it:

```bash
LEDGERBEETLE_LIB_DIR="$PWD/storage/ledgerbeetle" vendor/omacy/ledgerbeetle/bin/build-native.sh 0.17.9
```

```dotenv
TB_CLIENT_LIB=/path/to/app/storage/ledgerbeetle/libledgerbeetle_tb.so   # .dylib on macOS
```

Without `LEDGERBEETLE_LIB_DIR` the script writes to the package's own `resources/lib/<platform>/`, where it's found automatically but lost on the next `composer update`.

The script:

1. Downloads TigerBeetle's official [`tigerbeetle` wheel](https://pypi.org/project/tigerbeetle/) from PyPI, which bundles prebuilt `libtb_client` shared libraries for every platform.
2. Checks the wheel against the SHA-256 PyPI publishes.
3. Writes the output folder with `libtb_client.{so,dylib}` and the shim `libledgerbeetle_tb.{so,dylib}`, which finds the client library next to itself.

It needs `curl`, `unzip` and a C compiler. It supports macOS and Linux (glibc or musl) on x86_64 and arm64. Build it for each platform you deploy to, for example in your Docker image.

The C header the shim compiles against, [native/tb_client.h](native/tb_client.h), is pinned to TigerBeetle 0.17.9. Upgrade it together with the version you pass to the script; [native/README.md](native/README.md) says where it comes from.

Runtime requirements:

- **FFI must be allowed to load the library.** The CLI allows it by default. For PHP-FPM, set `ffi.enable=true` in php.ini.
- **The TigerBeetle client uses `io_uring` on Linux.** Docker's default seccomp profile blocks it, so a PHP container that talks to TigerBeetle needs `security_opt: [seccomp=unconfined]` or a profile that allows `io_uring_setup`, `io_uring_enter` and `io_uring_register`. Without it the client aborts the PHP process when it starts.

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

### Currency conversion

TigerBeetle only moves money within one ledger, so a conversion is a linked chain of transfers. All legs post or none do:

| Leg | From | To | Amount |
|---|---|---|---|
| fee (optional) | payer | fee account | fee, source currency |
| 1 | payer | `FX.POSITION.<SRC>` | source amount |
| 2 | `FX.POSITION.<TGT>` | payee | source × rate, rounded down |

```php
$conversion = Ledger::convert('WALLET.42', 'WALLET.42.EUR', Money::parse('50.00', 'USD'),
    idempotencyKey: 'fx-881', fee: 1_00, feeAccount: 'REVENUE.FEES.USD');   // rate from ExchangeRates

Ledger::convert('WALLET.42', 'WALLET.42.EUR', 50_00, rate: '0.9215', idempotencyKey: 'fx-882');  // explicit rate

Ledger::reverseConversion($conversion);   // every leg backwards at the original rate, once
```

How the rest of it works:

- **Position accounts.** `FX.POSITION.*` accounts are equity, created on first use. A negative balance means the platform is short that currency. Set `ledger.fx.prevent_short` to refuse conversions the platform can't cover.
- **Rates.** Rates come from `LedgerBeetle\Laravel\Contracts\ExchangeRates`. The default reads fixed rates from `ledger.fx.rates`; bind your own implementation for live rates.
- **Retries.** A retry reuses the rate stored by the first attempt, so a moving rate can't turn a retry into a conflict.
- **Single legs.** Individual conversion legs can't be reversed with `reverse()`; reverse the whole conversion instead.

### Balance history

New accounts get TigerBeetle's `history` flag (`ledger.balance_history`, default on). It can't be added to an existing account.

```php
Ledger::balanceAt('WALLET.42', now()->subMonth()->endOfMonth());   // Balance as it stood then
Ledger::balanceHistory('WALLET.42', from: now()->subWeek());          // BalanceSnapshot per transfer, newest first
```

### Batch posting

```php
// One TigerBeetle request. Items succeed or fail independently; failures are returned, not thrown.
$results = Ledger::transferBatch([
    ['from' => 'WALLET.42', 'to' => 'WALLET.77', 'amount' => 5_00, 'idempotency_key' => 'payout-1'],
    ['from' => 'WALLET.43', 'to' => 'WALLET.77', 'amount' => 7_00, 'idempotency_key' => 'payout-2'],
]);   // ['payout-1' => LedgerTransfer, 'payout-2' => InsufficientFunds, ...]

// Or off the request path: queues PostTransferBatch, then fires TransferBatchPosted.
$batchId = Ledger::queueBatch($items);
```

Every item has its own key, so a batch or a retried job never double-posts. Set the queue with `LEDGER_QUEUE_CONNECTION` and `LEDGER_QUEUE`.

### HTTP API

Enable it with `LEDGER_API=true`. Routes go under `/ledger` with the middleware in `ledger.api.middleware` (default `['api', 'auth']`).

> **The endpoints move money between any accounts they are given.** Put your own authentication and per-account authorization in that middleware before enabling them.

| Method | Path | Notes |
|---|---|---|
| POST | `/accounts` | `code, name, type, currency?, parent?, prevent_overdraft?, balance_history?` |
| GET | `/accounts/{code}` | Account plus live balance |
| GET | `/accounts/{code}/balance?at=` | Live, or as of a timestamp |
| GET | `/accounts/{code}/history?from=&to=&limit=` | Balance after each transfer |
| GET | `/accounts/{code}/transfers` | Paginated local records, including failed attempts |
| POST | `/transfers` | `from, to, amount, reference?` |
| GET | `/transfers/{id}` | |
| POST | `/transfers/{id}/reversals` | `amount?, reason?` |
| POST | `/transfer-batches` | `items[]` each with its own `idempotency_key`; returns 202 and queues the batch |
| POST | `/holds` | `from, to, amount, timeout_seconds?` |
| GET | `/holds/{id}` | |
| POST | `/holds/{id}/capture` · `/holds/{id}/release` | `amount?` on capture |
| POST | `/conversions` | `from, to, amount, rate?, fee?, fee_account?` |
| GET | `/conversions/{id}` | |
| POST | `/conversions/{id}/reversal` | |

Request rules:

- **Amounts** are decimal strings (`"12.50"`). They are rejected if they have more decimals than the currency allows.
- **Idempotency.** Money-moving POSTs need an `Idempotency-Key` header. Settling holds and reversing conversions accept one optionally, since they can only happen once.
- **Responses.** A new record returns `201`, a replay returns `200`, and errors look like `{"error": {"code": "...", "message": "..."}}`.

| Status | `error.code` | Meaning |
|---|---|---|
| 422 | `insufficient_funds` | The transfer would overdraw a guarded account |
| 422 | `invalid_operation` | The ledger refused the operation |
| 422 | other | TigerBeetle's own result name, e.g. `pending_transfer_expired` |
| 409 | `idempotency_conflict` | Same key, different parameters |
| 404 | `account_not_found` | |
| 503 | `ledger_unavailable` | Outcome unknown; retry with the same key |

### Idempotency

Always pass an `idempotencyKey` derived from your own request, such as an order id or an `Idempotency-Key` header.

- **Same key, same parameters:** you get the original result back, or the original failure rethrown. Money moves at most once.
- **Same key, different parameters:** `IdempotencyConflict`.
- **`ClusterUnavailable`** means TigerBeetle did not answer in time. The transfer may or may not have been applied. Retry with the same key to find out.

Every write first records a `pending` row with a fresh TigerBeetle id, then submits it. If the process dies between those steps, a retry resends the same id, and TigerBeetle answers `EXISTS` instead of posting twice.

### Inside your own database transactions

TigerBeetle isn't part of your database transaction. Once it accepts a transfer, rolling back your transaction doesn't undo it.

By default the ledger's rows use your default connection, so they roll back with your transaction. A retry with the same key then gets a fresh TigerBeetle id and posts again. If you call the ledger inside your own transactions, give it a second connection to the same database:

```php
// config/database.php
'connections' => [
    'mysql' => [/* ... */],
    'ledger' => [/* same database as mysql */],
],
```

```dotenv
LEDGER_DB_CONNECTION=ledger
```

Ledger rows then commit on their own:

- **Retries post once.** A retry finds the earlier row and resends the same TigerBeetle id.
- **Crashes are visible.** A row left behind by a crash is there for `ledger:reconcile` to find.

Undoing the money movement itself, such as releasing a hold or reversing a transfer when your transaction fails, is up to your app.

### Commands

| Command | What it does |
|---|---|
| `ledger:seed-accounts --currency=USD` | Starter chart of accounts (`ASSET.CASH.USD`, `REVENUE.FEES.USD`, …). Safe to rerun. |
| `ledger:sweep-holds` | Marks holds past their timeout as expired. Schedule it, e.g. every five minutes. |
| `ledger:reconcile [--fix] [--deep]` | Compares local rows with TigerBeetle: missing accounts, transfers, holds and conversions stuck in `pending`, and (with `--deep`) the amount of every posted transfer. |

## Testing your app

Set `LEDGER_GATEWAY=memory` to use `InMemoryGateway`, an in-process fake that copies the TigerBeetle rules this package relies on:

- idempotent ids
- linked chains
- balance-limit flags
- pending/post/void with timeouts that follow Carbon's clock
- tombstoned ids after transient failures
- balance history

## Developing this package

```bash
composer install
composer test                       # unit + feature tests, in-memory gateway

docker compose up -d                # single-replica TigerBeetle 0.17.9 on :3000
bin/build-native.sh
TB_ADDRESSES=127.0.0.1:3000 composer test:integration   # same scenarios against the real cluster
```

The feature and integration suites run the same scenarios ([tests/Scenarios.php](tests/Scenarios.php)), so the fake is checked against real TigerBeetle.

CI ([.github/workflows/tests.yml](.github/workflows/tests.yml)) runs the feature suite on PHP 8.2–8.4. It then builds the shim on Linux x86_64, starts TigerBeetle with `docker compose`, and runs the integration suite against it.

## Not in this version

- **Per-account API authorization.** That belongs to your application's middleware and policies.
- **Live exchange-rate providers.** Implement `ExchangeRates` to add one.
- **Closing accounts.** TigerBeetle's `closing_*` transfers aren't wrapped.
- **Change-event streaming** (`get_change_events`).

## License

MIT. See [LICENSE](LICENSE). The bundled [native/tb_client.h](native/tb_client.h) and the downloaded `libtb_client` are TigerBeetle's, under Apache-2.0.
