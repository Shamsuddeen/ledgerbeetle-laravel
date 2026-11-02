<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Exceptions;

class AccountNotFound extends LedgerException
{
    public static function code(string $code): self
    {
        return new self("Ledger account [{$code}] does not exist or is inactive.");
    }
}
