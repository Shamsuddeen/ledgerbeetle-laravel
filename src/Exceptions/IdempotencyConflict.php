<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Exceptions;

class IdempotencyConflict extends LedgerException
{
    public static function key(string $key): self
    {
        return new self("Idempotency key [{$key}] was already used with different parameters.");
    }
}
