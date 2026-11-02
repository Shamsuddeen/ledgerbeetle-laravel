<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Support;

/**
 * TigerBeetle object ids: 48-bit millisecond timestamp in the high bits, 80
 * random bits below (ULID layout). Time-ordered ids land at the end of TB's
 * LSM trees, which is what TigerBeetle recommends.
 */
final class Id
{
    public static function generate(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $bigEndian = substr(pack('J', $ms), 2).random_bytes(10);

        return U128::fromBytes(strrev($bigEndian));
    }
}
