<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Services;

use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LedgerBeetle\Laravel\Contracts\TigerBeetleGateway;
use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Exceptions\AccountNotFound;
use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;
use LedgerBeetle\Laravel\Exceptions\LedgerException;
use LedgerBeetle\Laravel\Gateways\Data\AccountBalanceState;
use LedgerBeetle\Laravel\Gateways\Data\AccountSpec;
use LedgerBeetle\Laravel\Gateways\Data\TransferState;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Support\Balance;
use LedgerBeetle\Laravel\Support\BalanceSnapshot;
use LedgerBeetle\Laravel\Support\Flags;
use LedgerBeetle\Laravel\Support\Id;
use LedgerBeetle\Laravel\Support\Ledgers;

final class AccountService
{
    /**
     * @param  array<string, bool>  $preventOverdraftByType
     */
    public function __construct(
        private readonly TigerBeetleGateway $gateway,
        private readonly Ledgers $ledgers,
        private readonly string $defaultCurrency,
        private readonly array $preventOverdraftByType,
        private readonly bool $balanceHistory = true,
    ) {}

    /**
     * Create an account, or return it if one with this code already exists
     * with the same type and currency. Safe to retry.
     *
     * @param  bool|null  $preventOverdraft  null uses config('ledger.prevent_overdraft')
     * @param  bool|null  $history  keep balance history (TB's history flag); null uses
     *                              config('ledger.balance_history'). Fixed once created.
     * @param  array<string, mixed>  $metadata
     */
    public function create(
        string $code,
        string $name,
        AccountType $type,
        ?string $currency = null,
        LedgerAccount|string|null $parent = null,
        ?bool $preventOverdraft = null,
        array $metadata = [],
        ?bool $history = null,
    ): LedgerAccount {
        $currency = strtoupper($currency ?? $this->defaultCurrency);
        $preventOverdraft ??= $this->preventOverdraftByType[$type->value] ?? false;
        $history ??= $this->balanceHistory;

        $account = LedgerAccount::query()->where('code', $code)->first();

        if ($account !== null) {
            if ($account->type !== $type || $account->currency !== $currency) {
                throw new InvalidLedgerOperation("Account [{$code}] already exists as {$account->type->value} in {$account->currency}.");
            }
        } else {
            try {
                $account = LedgerAccount::query()->create([
                    'tb_id' => Id::generate(),
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'currency' => $currency,
                    'ledger' => $this->ledgers->ledgerFor($currency),
                    'tb_code' => $type->tbCode(),
                    'flags' => ($preventOverdraft ? $type->overdraftFlag() : 0) | ($history ? Flags::ACCOUNT_HISTORY : 0),
                    'parent_id' => is_string($parent) ? $this->find($parent)->id : $parent?->id,
                    'metadata' => $metadata ?: null,
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->create($code, $name, $type, $currency, $parent, $preventOverdraft, $metadata, $history);
            }
        }

        $this->ensureInTigerBeetle($account);

        return $account;
    }

    /**
     * Create the TB side of an account row. Idempotent: EXISTS counts as done.
     */
    public function ensureInTigerBeetle(LedgerAccount $account): void
    {
        [$outcome] = $this->gateway->createAccounts([new AccountSpec(
            id: $account->tb_id,
            ledger: $account->ledger,
            code: $account->tb_code,
            flags: $account->flags,
        )]);

        if (! $outcome->ok()) {
            throw new LedgerException(
                "TigerBeetle rejected account [{$account->code}]: ".strtolower($outcome->result->name).'.'
            );
        }
    }

    public function find(string $code): LedgerAccount
    {
        return LedgerAccount::query()->active()->where('code', $code)->first()
            ?? throw AccountNotFound::code($code);
    }

    public function resolve(LedgerAccount|string $account): LedgerAccount
    {
        if (is_string($account)) {
            return $this->find($account);
        }

        if (! $account->is_active) {
            throw AccountNotFound::code($account->code);
        }

        return $account;
    }

    /**
     * Live balance straight from TigerBeetle.
     */
    public function balance(LedgerAccount|string $account): Balance
    {
        $account = is_string($account) ? $this->find($account) : $account;
        $state = $this->gateway->lookupAccounts([$account->tb_id])[$account->tb_id]
            ?? throw new LedgerException("Account [{$account->code}] is missing in TigerBeetle. Run ledger:reconcile.");

        return Balance::fromTotals(
            $account->currency,
            $account->type->isDebitNormal(),
            $state->debitsPending,
            $state->debitsPosted,
            $state->creditsPending,
            $state->creditsPosted,
        );
    }

    /**
     * The balance as it stood at $at. Needs an account created with history.
     */
    public function balanceAt(LedgerAccount|string $account, DateTimeInterface $at): Balance
    {
        $account = $this->withHistory($account);
        [$state] = $this->gateway->getAccountBalances($account->tb_id, 1, true, 0, BalanceSnapshot::tbTimestampOf($at)) + [null];

        return $state === null
            ? Balance::fromTotals($account->currency, $account->type->isDebitNormal(), 0, 0, 0, 0)
            : $this->toBalance($account, $state);
    }

    /**
     * The balance after each transfer in [$from, $to], newest first by default.
     *
     * @return list<BalanceSnapshot>
     */
    public function balanceHistory(
        LedgerAccount|string $account,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $to = null,
        int $limit = 100,
        bool $newestFirst = true,
    ): array {
        $account = $this->withHistory($account);
        $states = $this->gateway->getAccountBalances(
            $account->tb_id,
            $limit,
            $newestFirst,
            $from === null ? 0 : BalanceSnapshot::tbTimestampOf($from) - 999,
            $to === null ? 0 : BalanceSnapshot::tbTimestampOf($to),
        );

        return array_map(fn (AccountBalanceState $s) => new BalanceSnapshot(
            $this->toBalance($account, $s), BalanceSnapshot::timeOf($s->timestamp), $s->timestamp,
        ), $states);
    }

    /**
     * Raw TigerBeetle transfers touching the account.
     *
     * @return list<TransferState>
     */
    public function history(LedgerAccount|string $account, int $limit = 100, bool $newestFirst = true): array
    {
        $account = is_string($account) ? $this->find($account) : $account;

        return $this->gateway->getAccountTransfers($account->tb_id, $limit, $newestFirst);
    }

    private function withHistory(LedgerAccount|string $account): LedgerAccount
    {
        $account = is_string($account) ? $this->find($account) : $account;

        if (! Flags::has($account->flags, Flags::ACCOUNT_HISTORY)) {
            throw new InvalidLedgerOperation("Account [{$account->code}] was created without balance history.");
        }

        return $account;
    }

    private function toBalance(LedgerAccount $account, AccountBalanceState $s): Balance
    {
        return Balance::fromTotals($account->currency, $account->type->isDebitNormal(),
            $s->debitsPending, $s->debitsPosted, $s->creditsPending, $s->creditsPosted);
    }
}
