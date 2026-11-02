<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Http;

use Illuminate\Http\JsonResponse;
use LedgerBeetle\Laravel\Exceptions\AccountNotFound;
use LedgerBeetle\Laravel\Exceptions\ClusterUnavailable;
use LedgerBeetle\Laravel\Exceptions\IdempotencyConflict;
use LedgerBeetle\Laravel\Exceptions\InsufficientFunds;
use LedgerBeetle\Laravel\Exceptions\InvalidLedgerOperation;
use LedgerBeetle\Laravel\Exceptions\LedgerException;
use LedgerBeetle\Laravel\Exceptions\TransferRejected;

/**
 * JSON for ledger exceptions raised by the package's API routes:
 * {"error": {"code": "...", "message": "..."}}.
 */
final class ErrorResponse
{
    public static function for(LedgerException $e): JsonResponse
    {
        [$status, $code] = match (true) {
            $e instanceof InsufficientFunds => [422, 'insufficient_funds'],
            $e instanceof TransferRejected => [422, strtolower($e->result->name)],
            $e instanceof AccountNotFound => [404, 'account_not_found'],
            $e instanceof IdempotencyConflict => [409, 'idempotency_conflict'],
            $e instanceof InvalidLedgerOperation => [422, 'invalid_operation'],
            // The outcome is unknown; the client should retry with the same key.
            $e instanceof ClusterUnavailable => [503, 'ledger_unavailable'],
            default => [500, 'ledger_error'],
        };

        $response = response()->json(['error' => ['code' => $code, 'message' => $e->getMessage()]], $status);

        return $status === 503 ? $response->header('Retry-After', '1') : $response;
    }
}
