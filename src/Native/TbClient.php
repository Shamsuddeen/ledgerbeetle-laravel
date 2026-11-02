<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Native;

use FFI;
use FFI\CData;
use LedgerBeetle\Laravel\Exceptions\ClusterUnavailable;
use LedgerBeetle\Laravel\Exceptions\LedgerException;
use LedgerBeetle\Laravel\Support\U128;

/**
 * Blocking TigerBeetle client over the native/ledgerbeetle_tb.c shim.
 *
 * One instance holds one tb_client session. Not safe to share between
 * threads; one per PHP worker is the intended use.
 */
final class TbClient
{
    public const OP_LOOKUP_ACCOUNTS = 140;
    public const OP_LOOKUP_TRANSFERS = 141;
    public const OP_GET_ACCOUNT_TRANSFERS = 142;
    public const OP_CREATE_ACCOUNTS = 146;
    public const OP_CREATE_TRANSFERS = 147;

    private const HEADER = <<<'C'
        typedef struct lb_client lb_client;
        lb_client *lb_client_open(const uint8_t cluster_id[16], const char *addresses, uint32_t addresses_len, int *init_status_out);
        void lb_client_close(lb_client *c);
        int lb_request(lb_client *c, uint8_t operation, const uint8_t *data, uint32_t data_size,
                       uint8_t *reply, uint32_t reply_cap, uint32_t *reply_len_out,
                       uint8_t *packet_status_out, uint32_t timeout_ms);
        C;

    /** TB_INIT_STATUS names, by value. */
    private const INIT_STATUS = [
        1 => 'unexpected', 2 => 'out_of_memory', 3 => 'address_invalid',
        4 => 'address_limit_exceeded', 5 => 'system_resources', 6 => 'network_subsystem',
    ];

    /** TB_PACKET_STATUS names, by value. */
    private const PACKET_STATUS = [
        1 => 'too_much_data', 2 => 'client_evicted', 3 => 'client_release_too_low',
        4 => 'client_release_too_high', 5 => 'client_shutdown', 6 => 'invalid_operation',
        7 => 'invalid_data_size',
    ];

    private FFI $ffi;

    private ?CData $client;

    /**
     * @param  list<string>  $addresses  "host:port" for every replica
     */
    public function __construct(
        string $libraryPath,
        string $clusterId,
        array $addresses,
        private readonly int $timeoutMs = 5000,
    ) {
        if (! extension_loaded('ffi')) {
            throw new LedgerException('ext-ffi is required to talk to TigerBeetle.');
        }
        if (! is_file($libraryPath)) {
            throw new LedgerException("TigerBeetle client library not found at [{$libraryPath}]. Run bin/build-native.sh.");
        }

        $this->ffi = FFI::cdef(self::HEADER, $libraryPath);

        $cluster = $this->bytes(U128::toBytes($clusterId));
        $joined = implode(',', $addresses);
        $address = $this->ffi->new('char['.(strlen($joined) + 1).']');
        FFI::memcpy($address, $joined, strlen($joined));
        $status = $this->ffi->new('int');

        $this->client = $this->ffi->lb_client_open($cluster, $address, strlen($joined), FFI::addr($status));

        if ($this->client === null) {
            $reason = self::INIT_STATUS[$status->cdata] ?? "status {$status->cdata}";
            throw new LedgerException("Could not start the TigerBeetle client: {$reason}.");
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if (isset($this->client)) {
            $this->ffi->lb_client_close($this->client);
            $this->client = null;
        }
    }

    /**
     * Send one operation and return the raw reply bytes.
     */
    public function request(int $operation, string $events, int $replyCapacity): string
    {
        if ($this->client === null) {
            throw new LedgerException('The TigerBeetle client is closed.');
        }

        $reply = $this->ffi->new('uint8_t['.max(1, $replyCapacity).']');
        $replyLen = $this->ffi->new('uint32_t');
        $packetStatus = $this->ffi->new('uint8_t');

        $rc = $this->ffi->lb_request(
            $this->client, $operation, $this->bytes($events), strlen($events),
            $reply, $replyCapacity, FFI::addr($replyLen), FFI::addr($packetStatus), $this->timeoutMs,
        );

        match ($rc) {
            0 => null,
            -1 => throw new ClusterUnavailable("TigerBeetle did not answer within {$this->timeoutMs} ms."),
            -2 => throw new LedgerException('The TigerBeetle client handle is invalid.'),
            -3 => throw new LedgerException('Out of memory while preparing a TigerBeetle request.'),
            -4 => throw new LedgerException("TigerBeetle reply of {$replyLen->cdata} bytes exceeded the expected {$replyCapacity}."),
            default => throw new LedgerException("Unexpected shim result {$rc}."),
        };

        if ($packetStatus->cdata !== 0) {
            $reason = self::PACKET_STATUS[$packetStatus->cdata] ?? "status {$packetStatus->cdata}";
            $class = in_array($reason, ['client_evicted', 'client_shutdown'], true) ? ClusterUnavailable::class : LedgerException::class;

            throw new $class("TigerBeetle rejected the request packet: {$reason}.");
        }

        return $replyLen->cdata === 0 ? '' : FFI::string($reply, $replyLen->cdata);
    }

    private function bytes(string $data): CData
    {
        $buffer = $this->ffi->new('uint8_t['.max(1, strlen($data)).']');
        if ($data !== '') {
            FFI::memcpy($buffer, $data, strlen($data));
        }

        return $buffer;
    }
}
