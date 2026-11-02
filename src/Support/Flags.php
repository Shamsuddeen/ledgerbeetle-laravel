<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Support;

/**
 * TigerBeetle flag bits. Kept here so the in-memory gateway and the services
 * do not depend on the FFI client.
 */
final class Flags
{
    public const ACCOUNT_LINKED = 1 << 0;
    public const ACCOUNT_DEBITS_MUST_NOT_EXCEED_CREDITS = 1 << 1;
    public const ACCOUNT_CREDITS_MUST_NOT_EXCEED_DEBITS = 1 << 2;
    public const ACCOUNT_HISTORY = 1 << 3;
    public const ACCOUNT_IMPORTED = 1 << 4;
    public const ACCOUNT_CLOSED = 1 << 5;

    public const TRANSFER_LINKED = 1 << 0;
    public const TRANSFER_PENDING = 1 << 1;
    public const TRANSFER_POST_PENDING = 1 << 2;
    public const TRANSFER_VOID_PENDING = 1 << 3;
    public const TRANSFER_BALANCING_DEBIT = 1 << 4;
    public const TRANSFER_BALANCING_CREDIT = 1 << 5;
    public const TRANSFER_CLOSING_DEBIT = 1 << 6;
    public const TRANSFER_CLOSING_CREDIT = 1 << 7;
    public const TRANSFER_IMPORTED = 1 << 8;

    public static function has(int $flags, int $flag): bool
    {
        return ($flags & $flag) === $flag;
    }
}
