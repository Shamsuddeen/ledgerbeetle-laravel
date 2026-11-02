<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Exceptions;

use LedgerBeetle\Laravel\Enums\CreateAccountResult;
use LedgerBeetle\Laravel\Enums\CreateTransferResult;

/**
 * TigerBeetle refused the event. $result holds its exact reason.
 */
class TransferRejected extends LedgerException
{
    public function __construct(
        public readonly CreateTransferResult|CreateAccountResult $result,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'TigerBeetle rejected the request: '.strtolower($result->name).'.');
    }

    /**
     * Rebuild the exception for a failure recorded on an earlier attempt.
     */
    public static function fromRecorded(string $reason): LedgerException
    {
        $case = CreateTransferResult::class.'::'.$reason;

        return defined($case)
            ? self::for(constant($case))
            : new LedgerException("Transfer failed on an earlier attempt: {$reason}.");
    }

    public static function for(CreateTransferResult $result): self
    {
        return $result === CreateTransferResult::EXCEEDS_CREDITS || $result === CreateTransferResult::EXCEEDS_DEBITS
            ? new InsufficientFunds($result)
            : new self($result);
    }
}
