<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Gateways;

use Illuminate\Support\Carbon;
use LedgerBeetle\Laravel\Contracts\TigerBeetleGateway;
use LedgerBeetle\Laravel\Enums\CreateAccountResult as AR;
use LedgerBeetle\Laravel\Enums\CreateTransferResult as TR;
use LedgerBeetle\Laravel\Gateways\Data\AccountSpec;
use LedgerBeetle\Laravel\Gateways\Data\AccountState;
use LedgerBeetle\Laravel\Gateways\Data\CreateOutcome;
use LedgerBeetle\Laravel\Gateways\Data\TransferSpec;
use LedgerBeetle\Laravel\Gateways\Data\TransferState;
use LedgerBeetle\Laravel\Support\Flags;
use LedgerBeetle\Laravel\Support\U128;

/**
 * In-process stand-in for a TigerBeetle cluster.
 *
 * It reproduces the rules this package relies on: idempotent ids, linked
 * chains, balance-limit flags, pending/post/void with timeouts, and tombstoned
 * ids after transient failures. It is not a full TB implementation: there are
 * no overflow checks, closing or balancing transfers, or imported events.
 * Timeouts follow Carbon::now(), so tests can travel in time.
 */
final class InMemoryGateway implements TigerBeetleGateway
{
    /** @var array<string, array<string, mixed>> */
    private array $accounts = [];

    /** @var array<string, TransferState> */
    private array $transfers = [];

    /** @var array<string, 'pending'|'posted'|'voided'|'expired'> */
    private array $pendingState = [];

    /** @var array<string, true> */
    private array $failedIds = [];

    private int $lastTimestamp = 0;

    /**
     * Results TB treats as transient: the id is tombstoned and retrying it
     * returns ID_ALREADY_FAILED.
     */
    private const TRANSIENT = [
        TR::DEBIT_ACCOUNT_NOT_FOUND,
        TR::CREDIT_ACCOUNT_NOT_FOUND,
        TR::PENDING_TRANSFER_NOT_FOUND,
        TR::EXCEEDS_CREDITS,
        TR::EXCEEDS_DEBITS,
        TR::PENDING_TRANSFER_EXPIRED,
    ];

    public function createAccounts(array $accounts): array
    {
        return $this->runChains($accounts, AR::LINKED_EVENT_FAILED, AR::LINKED_EVENT_CHAIN_OPEN, Flags::ACCOUNT_LINKED,
            fn (AccountSpec $a) => $this->createAccount($a));
    }

    public function createTransfers(array $transfers): array
    {
        $this->expirePending();

        return $this->runChains($transfers, TR::LINKED_EVENT_FAILED, TR::LINKED_EVENT_CHAIN_OPEN, Flags::TRANSFER_LINKED,
            fn (TransferSpec $t) => $this->createTransfer($t));
    }

    public function lookupAccounts(array $ids): array
    {
        $this->expirePending();

        $found = [];
        foreach ($ids as $id) {
            $found[$id] = isset($this->accounts[$id]) ? $this->accountState($this->accounts[$id]) : null;
        }

        return $found;
    }

    public function lookupTransfers(array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            $found[$id] = $this->transfers[$id] ?? null;
        }

        return $found;
    }

    public function getAccountTransfers(string $accountId, int $limit = 100, bool $newestFirst = false): array
    {
        $matches = array_values(array_filter(
            $this->transfers,
            fn (TransferState $t) => $t->debitAccountId === $accountId || $t->creditAccountId === $accountId,
        ));

        usort($matches, fn (TransferState $a, TransferState $b) => $a->timestamp <=> $b->timestamp);

        if ($newestFirst) {
            $matches = array_reverse($matches);
        }

        return array_slice($matches, 0, $limit);
    }

    /**
     * Apply events chain by chain. A failed event in a linked chain rolls back
     * the whole chain, and its siblings report LINKED_EVENT_FAILED.
     *
     * @template T of AccountSpec|TransferSpec
     *
     * @param  list<T>  $events
     * @param  callable(T): CreateOutcome  $apply
     * @return list<CreateOutcome>
     */
    private function runChains(array $events, AR|TR $linkedFailed, AR|TR $chainOpen, int $linkedFlag, callable $apply): array
    {
        $results = [];
        $count = count($events);
        $i = 0;

        while ($i < $count) {
            $start = $i;
            while ($i < $count - 1 && Flags::has($events[$i]->flags, $linkedFlag)) {
                $i++;
            }
            $end = $i;
            $i++;

            if (Flags::has($events[$end]->flags, $linkedFlag)) {
                for ($j = $start; $j <= $end; $j++) {
                    $results[$j] = new CreateOutcome($j === $end ? $chainOpen : $linkedFailed, $this->tick());
                }

                continue;
            }

            // Tombstones are not rolled back: a transient failure burns its id
            // even inside a chain.
            $snapshot = [$this->accounts, $this->transfers, $this->pendingState];
            $chain = [];
            $failedAt = null;

            for ($j = $start; $j <= $end; $j++) {
                $chain[$j] = $apply($events[$j]);
                // Inside a linked chain even EXISTS breaks the chain, as in TB.
                if ($start === $end ? ! $chain[$j]->ok() : $chain[$j]->result->name !== 'CREATED') {
                    $failedAt = $j;
                    break;
                }
            }

            if ($failedAt === null) {
                $results += $chain;

                continue;
            }

            [$this->accounts, $this->transfers, $this->pendingState] = $snapshot;
            for ($j = $start; $j <= $end; $j++) {
                $results[$j] = $j === $failedAt ? $chain[$j] : new CreateOutcome($linkedFailed, $this->tick());
            }
        }

        ksort($results);

        return array_values($results);
    }

    private function createAccount(AccountSpec $a): CreateOutcome
    {
        $fail = fn (AR $r) => new CreateOutcome($r, $this->tick());

        if ($a->id === '0') {
            return $fail(AR::ID_MUST_NOT_BE_ZERO);
        }
        if ($a->id === U128::MAX) {
            return $fail(AR::ID_MUST_NOT_BE_INT_MAX);
        }
        if (Flags::has($a->flags, Flags::ACCOUNT_DEBITS_MUST_NOT_EXCEED_CREDITS | Flags::ACCOUNT_CREDITS_MUST_NOT_EXCEED_DEBITS)) {
            return $fail(AR::FLAGS_ARE_MUTUALLY_EXCLUSIVE);
        }

        $flags = $a->flags & ~Flags::ACCOUNT_LINKED;

        if ($existing = $this->accounts[$a->id] ?? null) {
            return match (true) {
                $existing['flags'] !== $flags => $fail(AR::EXISTS_WITH_DIFFERENT_FLAGS),
                $existing['userData128'] !== $a->userData128 => $fail(AR::EXISTS_WITH_DIFFERENT_USER_DATA_128),
                $existing['userData64'] !== $a->userData64 => $fail(AR::EXISTS_WITH_DIFFERENT_USER_DATA_64),
                $existing['userData32'] !== $a->userData32 => $fail(AR::EXISTS_WITH_DIFFERENT_USER_DATA_32),
                $existing['ledger'] !== $a->ledger => $fail(AR::EXISTS_WITH_DIFFERENT_LEDGER),
                $existing['code'] !== $a->code => $fail(AR::EXISTS_WITH_DIFFERENT_CODE),
                default => new CreateOutcome(AR::EXISTS, $existing['timestamp']),
            };
        }

        if ($a->ledger === 0) {
            return $fail(AR::LEDGER_MUST_NOT_BE_ZERO);
        }
        if ($a->code === 0) {
            return $fail(AR::CODE_MUST_NOT_BE_ZERO);
        }

        $timestamp = $this->tick();
        $this->accounts[$a->id] = [
            'id' => $a->id,
            'ledger' => $a->ledger,
            'code' => $a->code,
            'flags' => $flags,
            'userData128' => $a->userData128,
            'userData64' => $a->userData64,
            'userData32' => $a->userData32,
            'debitsPending' => 0,
            'debitsPosted' => 0,
            'creditsPending' => 0,
            'creditsPosted' => 0,
            'timestamp' => $timestamp,
        ];

        return new CreateOutcome(AR::CREATED, $timestamp);
    }

    private function createTransfer(TransferSpec $t): CreateOutcome
    {
        $result = $this->validateTransfer($t);

        if ($result instanceof CreateOutcome) {
            return $result;
        }

        if ($result !== null) {
            if (in_array($result, self::TRANSIENT, true)) {
                $this->failedIds[$t->id] = true;
            }

            return new CreateOutcome($result, $this->tick());
        }

        return $this->applyTransfer($t);
    }

    /**
     * @return TR|CreateOutcome|null null when the transfer may be applied
     */
    private function validateTransfer(TransferSpec $t): TR|CreateOutcome|null
    {
        $isPending = Flags::has($t->flags, Flags::TRANSFER_PENDING);
        $isPost = Flags::has($t->flags, Flags::TRANSFER_POST_PENDING);
        $isVoid = Flags::has($t->flags, Flags::TRANSFER_VOID_PENDING);
        $flags = $t->flags & ~Flags::TRANSFER_LINKED;

        if ($t->id === '0') {
            return TR::ID_MUST_NOT_BE_ZERO;
        }
        if ($t->id === U128::MAX) {
            return TR::ID_MUST_NOT_BE_INT_MAX;
        }

        if ($existing = $this->transfers[$t->id] ?? null) {
            return match (true) {
                $existing->flags !== $flags => TR::EXISTS_WITH_DIFFERENT_FLAGS,
                $existing->pendingId !== $t->pendingId => TR::EXISTS_WITH_DIFFERENT_PENDING_ID,
                $existing->timeout !== $t->timeout => TR::EXISTS_WITH_DIFFERENT_TIMEOUT,
                $t->debitAccountId !== '0' && $existing->debitAccountId !== $t->debitAccountId => TR::EXISTS_WITH_DIFFERENT_DEBIT_ACCOUNT_ID,
                $t->creditAccountId !== '0' && $existing->creditAccountId !== $t->creditAccountId => TR::EXISTS_WITH_DIFFERENT_CREDIT_ACCOUNT_ID,
                $t->amount !== null && $existing->amount !== $t->amount && ! $isVoid => TR::EXISTS_WITH_DIFFERENT_AMOUNT,
                $existing->userData128 !== $t->userData128 && $t->userData128 !== '0' => TR::EXISTS_WITH_DIFFERENT_USER_DATA_128,
                $existing->userData64 !== $t->userData64 && $t->userData64 !== 0 => TR::EXISTS_WITH_DIFFERENT_USER_DATA_64,
                $existing->userData32 !== $t->userData32 && $t->userData32 !== 0 => TR::EXISTS_WITH_DIFFERENT_USER_DATA_32,
                $t->ledger !== 0 && $existing->ledger !== $t->ledger => TR::EXISTS_WITH_DIFFERENT_LEDGER,
                $t->code !== 0 && $existing->code !== $t->code => TR::EXISTS_WITH_DIFFERENT_CODE,
                default => new CreateOutcome(TR::EXISTS, $existing->timestamp),
            };
        }

        if (isset($this->failedIds[$t->id])) {
            return TR::ID_ALREADY_FAILED;
        }

        if (($isPending && ($isPost || $isVoid)) || ($isPost && $isVoid)) {
            return TR::FLAGS_ARE_MUTUALLY_EXCLUSIVE;
        }

        if ($isPost || $isVoid) {
            return $this->validateSettlement($t, $isPost);
        }

        if ($t->debitAccountId === '0') {
            return TR::DEBIT_ACCOUNT_ID_MUST_NOT_BE_ZERO;
        }
        if ($t->creditAccountId === '0') {
            return TR::CREDIT_ACCOUNT_ID_MUST_NOT_BE_ZERO;
        }
        if ($t->debitAccountId === $t->creditAccountId) {
            return TR::ACCOUNTS_MUST_BE_DIFFERENT;
        }
        if ($t->pendingId !== '0') {
            return TR::PENDING_ID_MUST_BE_ZERO;
        }
        if ($t->timeout !== 0 && ! $isPending) {
            return TR::TIMEOUT_RESERVED_FOR_PENDING_TRANSFER;
        }
        if ($t->ledger === 0) {
            return TR::LEDGER_MUST_NOT_BE_ZERO;
        }
        if ($t->code === 0) {
            return TR::CODE_MUST_NOT_BE_ZERO;
        }

        $dr = $this->accounts[$t->debitAccountId] ?? null;
        $cr = $this->accounts[$t->creditAccountId] ?? null;

        if ($dr === null) {
            return TR::DEBIT_ACCOUNT_NOT_FOUND;
        }
        if ($cr === null) {
            return TR::CREDIT_ACCOUNT_NOT_FOUND;
        }
        if ($dr['ledger'] !== $cr['ledger']) {
            return TR::ACCOUNTS_MUST_HAVE_THE_SAME_LEDGER;
        }
        if ($t->ledger !== $dr['ledger']) {
            return TR::TRANSFER_MUST_HAVE_THE_SAME_LEDGER_AS_ACCOUNTS;
        }

        $amount = (int) $t->amount;

        if (Flags::has($dr['flags'], Flags::ACCOUNT_DEBITS_MUST_NOT_EXCEED_CREDITS)
            && $dr['debitsPending'] + $dr['debitsPosted'] + $amount > $dr['creditsPosted']) {
            return TR::EXCEEDS_CREDITS;
        }
        if (Flags::has($cr['flags'], Flags::ACCOUNT_CREDITS_MUST_NOT_EXCEED_DEBITS)
            && $cr['creditsPending'] + $cr['creditsPosted'] + $amount > $cr['debitsPosted']) {
            return TR::EXCEEDS_DEBITS;
        }

        return null;
    }

    private function validateSettlement(TransferSpec $t, bool $isPost): ?TR
    {
        if ($t->pendingId === '0') {
            return TR::PENDING_ID_MUST_NOT_BE_ZERO;
        }
        if ($t->pendingId === $t->id) {
            return TR::PENDING_ID_MUST_BE_DIFFERENT;
        }
        if ($t->timeout !== 0) {
            return TR::TIMEOUT_RESERVED_FOR_PENDING_TRANSFER;
        }

        $pending = $this->transfers[$t->pendingId] ?? null;

        if ($pending === null) {
            return TR::PENDING_TRANSFER_NOT_FOUND;
        }
        if (! Flags::has($pending->flags, Flags::TRANSFER_PENDING)) {
            return TR::PENDING_TRANSFER_NOT_PENDING;
        }
        if ($t->debitAccountId !== '0' && $t->debitAccountId !== $pending->debitAccountId) {
            return TR::PENDING_TRANSFER_HAS_DIFFERENT_DEBIT_ACCOUNT_ID;
        }
        if ($t->creditAccountId !== '0' && $t->creditAccountId !== $pending->creditAccountId) {
            return TR::PENDING_TRANSFER_HAS_DIFFERENT_CREDIT_ACCOUNT_ID;
        }
        if ($t->ledger !== 0 && $t->ledger !== $pending->ledger) {
            return TR::PENDING_TRANSFER_HAS_DIFFERENT_LEDGER;
        }
        if ($t->code !== 0 && $t->code !== $pending->code) {
            return TR::PENDING_TRANSFER_HAS_DIFFERENT_CODE;
        }
        if ($isPost && $t->amount !== null && $t->amount > $pending->amount) {
            return TR::EXCEEDS_PENDING_TRANSFER_AMOUNT;
        }
        if (! $isPost && $t->amount !== null && $t->amount !== 0 && $t->amount !== $pending->amount) {
            return TR::PENDING_TRANSFER_HAS_DIFFERENT_AMOUNT;
        }

        return match ($this->pendingState[$t->pendingId]) {
            'posted' => TR::PENDING_TRANSFER_ALREADY_POSTED,
            'voided' => TR::PENDING_TRANSFER_ALREADY_VOIDED,
            'expired' => TR::PENDING_TRANSFER_EXPIRED,
            default => null,
        };
    }

    private function applyTransfer(TransferSpec $t): CreateOutcome
    {
        $timestamp = $this->tick();
        $flags = $t->flags & ~Flags::TRANSFER_LINKED;
        $isPost = Flags::has($flags, Flags::TRANSFER_POST_PENDING);
        $isVoid = Flags::has($flags, Flags::TRANSFER_VOID_PENDING);

        if ($isPost || $isVoid) {
            $pending = $this->transfers[$t->pendingId];
            $amount = $isPost ? ($t->amount ?? $pending->amount) : $pending->amount;

            $this->release($pending);
            if ($isPost) {
                $this->accounts[$pending->debitAccountId]['debitsPosted'] += $amount;
                $this->accounts[$pending->creditAccountId]['creditsPosted'] += $amount;
            }
            $this->pendingState[$t->pendingId] = $isPost ? 'posted' : 'voided';

            $this->transfers[$t->id] = new TransferState(
                $t->id, $pending->debitAccountId, $pending->creditAccountId, $amount, $t->pendingId,
                $pending->ledger, $pending->code, $flags, 0, $timestamp,
                $t->userData128 !== '0' ? $t->userData128 : $pending->userData128,
                $t->userData64 ?: $pending->userData64,
                $t->userData32 ?: $pending->userData32,
            );

            return new CreateOutcome(TR::CREATED, $timestamp);
        }

        $amount = (int) $t->amount;

        if (Flags::has($flags, Flags::TRANSFER_PENDING)) {
            $this->accounts[$t->debitAccountId]['debitsPending'] += $amount;
            $this->accounts[$t->creditAccountId]['creditsPending'] += $amount;
            $this->pendingState[$t->id] = 'pending';
        } else {
            $this->accounts[$t->debitAccountId]['debitsPosted'] += $amount;
            $this->accounts[$t->creditAccountId]['creditsPosted'] += $amount;
        }

        $this->transfers[$t->id] = new TransferState(
            $t->id, $t->debitAccountId, $t->creditAccountId, $amount, '0',
            $t->ledger, $t->code, $flags, $t->timeout, $timestamp,
            $t->userData128, $t->userData64, $t->userData32,
        );

        return new CreateOutcome(TR::CREATED, $timestamp);
    }

    private function release(TransferState $pending): void
    {
        $this->accounts[$pending->debitAccountId]['debitsPending'] -= $pending->amount;
        $this->accounts[$pending->creditAccountId]['creditsPending'] -= $pending->amount;
    }

    private function expirePending(): void
    {
        $now = $this->clock();

        foreach ($this->pendingState as $id => $state) {
            $t = $this->transfers[$id];
            if ($state === 'pending' && $t->timeout > 0 && $t->timestamp + $t->timeout * 1_000_000_000 <= $now) {
                $this->release($t);
                $this->pendingState[$id] = 'expired';
            }
        }
    }

    /**
     * Strictly increasing nanosecond timestamps, like TB's cluster clock.
     */
    private function tick(): int
    {
        return $this->lastTimestamp = max($this->lastTimestamp + 1, $this->clock());
    }

    private function clock(): int
    {
        $now = Carbon::now();

        return $now->getTimestamp() * 1_000_000_000 + (int) $now->format('u') * 1_000;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function accountState(array $a): AccountState
    {
        return new AccountState(
            $a['id'], $a['ledger'], $a['code'], $a['flags'],
            $a['debitsPending'], $a['debitsPosted'], $a['creditsPending'], $a['creditsPosted'],
            $a['timestamp'], $a['userData128'], $a['userData64'], $a['userData32'],
        );
    }
}
