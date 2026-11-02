<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Gateways;

use Closure;
use LedgerBeetle\Laravel\Contracts\TigerBeetleGateway;
use LedgerBeetle\Laravel\Enums\CreateAccountResult;
use LedgerBeetle\Laravel\Enums\CreateTransferResult;
use LedgerBeetle\Laravel\Exceptions\LedgerException;
use LedgerBeetle\Laravel\Gateways\Data\AccountBalanceState;
use LedgerBeetle\Laravel\Gateways\Data\AccountSpec;
use LedgerBeetle\Laravel\Gateways\Data\AccountState;
use LedgerBeetle\Laravel\Gateways\Data\CreateOutcome;
use LedgerBeetle\Laravel\Gateways\Data\TransferSpec;
use LedgerBeetle\Laravel\Gateways\Data\TransferState;
use LedgerBeetle\Laravel\Native\TbClient;
use LedgerBeetle\Laravel\Support\U128;

/**
 * TigerBeetle gateway over the native tb_client shim. Encodes and decodes the
 * 128-byte Account/Transfer structs from tb_client.h 0.17.
 */
final class NativeGateway implements TigerBeetleGateway
{
    /** Events per request TB accepts (1 MiB message / 128-byte events). */
    public const MAX_BATCH = 8189;

    private const EVENT_SIZE = 128;

    private const RESULT_SIZE = 16;

    private const FILTER_DEBITS = 1 << 0;

    private const FILTER_CREDITS = 1 << 1;

    private const FILTER_REVERSED = 1 << 2;

    private ?TbClient $client = null;

    /**
     * @param  Closure(): TbClient  $connect
     */
    public function __construct(
        private readonly Closure $connect,
    ) {}

    public function close(): void
    {
        $this->client?->close();
        $this->client = null;
    }

    public function createAccounts(array $accounts): array
    {
        $events = implode('', array_map(fn (AccountSpec $a) => U128::toBytes($a->id)
            .str_repeat("\0", 64) // debits/credits pending/posted: must be zero
            .U128::toBytes($a->userData128)
            .pack('PVVVvvP', $a->userData64, $a->userData32, 0, $a->ledger, $a->code, $a->flags, 0), $accounts));

        return $this->create(TbClient::OP_CREATE_ACCOUNTS, $events, count($accounts), CreateAccountResult::class);
    }

    public function createTransfers(array $transfers): array
    {
        $events = implode('', array_map(fn (TransferSpec $t) => U128::toBytes($t->id)
            .U128::toBytes($t->debitAccountId)
            .U128::toBytes($t->creditAccountId)
            .U128::toBytes($t->amount === null ? U128::MAX : (string) $t->amount)
            .U128::toBytes($t->pendingId)
            .U128::toBytes($t->userData128)
            .pack('PVVVvvP', $t->userData64, $t->userData32, $t->timeout, $t->ledger, $t->code, $t->flags, 0), $transfers));

        return $this->create(TbClient::OP_CREATE_TRANSFERS, $events, count($transfers), CreateTransferResult::class);
    }

    public function lookupAccounts(array $ids): array
    {
        return $this->lookup(TbClient::OP_LOOKUP_ACCOUNTS, $ids, function (string $s) {
            $f = unpack('Pud64/Vud32/Vreserved/Vledger/vcode/vflags/Ptimestamp', $s, 96);

            return new AccountState(
                U128::fromBytes(substr($s, 0, 16)),
                $f['ledger'], $f['code'], $f['flags'],
                $this->amount($s, 16), $this->amount($s, 32), $this->amount($s, 48), $this->amount($s, 64),
                $f['timestamp'], U128::fromBytes(substr($s, 80, 16)), $f['ud64'], $f['ud32'],
            );
        });
    }

    public function lookupTransfers(array $ids): array
    {
        return $this->lookup(TbClient::OP_LOOKUP_TRANSFERS, $ids, $this->decodeTransfer(...));
    }

    public function getAccountTransfers(string $accountId, int $limit = 100, bool $newestFirst = false): array
    {
        $limit = min($limit, self::MAX_BATCH);
        $reply = $this->client()->request(TbClient::OP_GET_ACCOUNT_TRANSFERS,
            $this->accountFilter($accountId, $limit, $newestFirst, 0, 0), $limit * self::EVENT_SIZE);

        return array_map($this->decodeTransfer(...), str_split($reply, self::EVENT_SIZE) ?: []);
    }

    public function getAccountBalances(
        string $accountId,
        int $limit = 100,
        bool $newestFirst = false,
        int $timestampMin = 0,
        int $timestampMax = 0,
    ): array {
        $limit = min($limit, self::MAX_BATCH);
        $reply = $this->client()->request(TbClient::OP_GET_ACCOUNT_BALANCES,
            $this->accountFilter($accountId, $limit, $newestFirst, $timestampMin, $timestampMax), $limit * self::EVENT_SIZE);

        return array_map(fn (string $s) => new AccountBalanceState(
            $this->amount($s, 0), $this->amount($s, 16), $this->amount($s, 32), $this->amount($s, 48),
            unpack('P', $s, 64)[1],
        ), str_split($reply, self::EVENT_SIZE) ?: []);
    }

    /**
     * tb_account_filter_t: transfers on either side of the account.
     */
    private function accountFilter(string $accountId, int $limit, bool $newestFirst, int $timestampMin, int $timestampMax): string
    {
        return U128::toBytes($accountId)
            .str_repeat("\0", 16)                     // user_data_128: any
            .pack('PVv', 0, 0, 0)                     // user_data_64/32, code: any
            .str_repeat("\0", 58)                     // reserved
            .pack('PPVV', $timestampMin, $timestampMax, $limit,
                self::FILTER_DEBITS | self::FILTER_CREDITS | ($newestFirst ? self::FILTER_REVERSED : 0));
    }

    private function client(): TbClient
    {
        return $this->client ??= ($this->connect)();
    }

    /**
     * @param  class-string<CreateAccountResult|CreateTransferResult>  $enum
     * @return list<CreateOutcome>
     */
    private function create(int $operation, string $events, int $count, string $enum): array
    {
        if ($count === 0) {
            return [];
        }
        if ($count > self::MAX_BATCH) {
            throw new LedgerException('TigerBeetle accepts at most '.self::MAX_BATCH." events per request, got {$count}.");
        }

        $reply = $this->client()->request($operation, $events, $count * self::RESULT_SIZE);
        $outcomes = [];

        foreach (str_split($reply, self::RESULT_SIZE) ?: [] as $result) {
            ['timestamp' => $timestamp, 'status' => $status] = unpack('Ptimestamp/Vstatus', $result);
            $outcomes[] = new CreateOutcome($enum::from($status), $timestamp);
        }

        if (count($outcomes) !== $count) {
            throw new LedgerException("TigerBeetle returned ".count($outcomes)." results for {$count} events.");
        }

        return $outcomes;
    }

    /**
     * @template T
     *
     * @param  list<string>  $ids
     * @param  callable(string): T  $decode
     * @return array<string, T|null>
     */
    private function lookup(int $operation, array $ids, callable $decode): array
    {
        $found = array_fill_keys($ids, null);

        foreach (array_chunk($ids, self::MAX_BATCH) as $chunk) {
            $events = implode('', array_map(U128::toBytes(...), $chunk));
            $reply = $this->client()->request($operation, $events, count($chunk) * self::EVENT_SIZE);

            foreach (str_split($reply, self::EVENT_SIZE) ?: [] as $struct) {
                $object = $decode($struct);
                // Missing ids come back absent or as zeroed structs.
                if ($object->timestamp !== 0 && array_key_exists($object->id, $found)) {
                    $found[$object->id] = $object;
                }
            }
        }

        return $found;
    }

    private function decodeTransfer(string $s): TransferState
    {
        $f = unpack('Pud64/Vud32/Vtimeout/Vledger/vcode/vflags/Ptimestamp', $s, 96);

        return new TransferState(
            U128::fromBytes(substr($s, 0, 16)),
            U128::fromBytes(substr($s, 16, 16)),
            U128::fromBytes(substr($s, 32, 16)),
            $this->amount($s, 48),
            U128::fromBytes(substr($s, 64, 16)),
            $f['ledger'], $f['code'], $f['flags'], $f['timeout'], $f['timestamp'],
            U128::fromBytes(substr($s, 80, 16)), $f['ud64'], $f['ud32'],
        );
    }

    private function amount(string $struct, int $offset): int
    {
        return U128::toInt(U128::fromBytes(substr($struct, $offset, 16)));
    }
}
