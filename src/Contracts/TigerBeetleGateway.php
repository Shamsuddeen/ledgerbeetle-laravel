<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Contracts;

use LedgerBeetle\Laravel\Gateways\Data\AccountSpec;
use LedgerBeetle\Laravel\Gateways\Data\AccountState;
use LedgerBeetle\Laravel\Gateways\Data\CreateOutcome;
use LedgerBeetle\Laravel\Gateways\Data\TransferSpec;
use LedgerBeetle\Laravel\Gateways\Data\TransferState;

/**
 * The only door to TigerBeetle. Ids and 128-bit fields cross it as decimal
 * strings; amounts as non-negative PHP ints.
 */
interface TigerBeetleGateway
{
    /**
     * @param  list<AccountSpec>  $accounts
     * @return list<CreateOutcome> one per account, same order
     */
    public function createAccounts(array $accounts): array;

    /**
     * @param  list<TransferSpec>  $transfers
     * @return list<CreateOutcome> one per transfer, same order
     */
    public function createTransfers(array $transfers): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, AccountState|null> keyed by id; null when not found
     */
    public function lookupAccounts(array $ids): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, TransferState|null> keyed by id; null when not found
     */
    public function lookupTransfers(array $ids): array;

    /**
     * Transfers touching an account, oldest first unless $newestFirst.
     *
     * @return list<TransferState>
     */
    public function getAccountTransfers(string $accountId, int $limit = 100, bool $newestFirst = false): array;
}
