<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Support;

use InvalidArgumentException;
use OverflowException;

/**
 * Helpers for unsigned 128-bit values held as decimal strings. Uses ext-gmp
 * when loaded, ext-bcmath otherwise.
 */
final class U128
{
    public const MAX = '340282366920938463463374607431768211455';

    /**
     * Narrow a decimal string to a PHP int, refusing values that do not fit.
     */
    public static function toInt(string $value): int
    {
        $value = ltrim($value, '0') ?: '0';
        $max = (string) PHP_INT_MAX;

        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            throw new OverflowException("Value {$value} does not fit in a PHP int.");
        }

        return (int) $value;
    }

    /**
     * Decimal string to 16 little-endian bytes, TigerBeetle's wire format.
     */
    public static function toBytes(string $decimal): string
    {
        if (! ctype_digit($decimal)) {
            throw new InvalidArgumentException("[{$decimal}] is not an unsigned decimal integer.");
        }

        $hex = extension_loaded('gmp') ? gmp_strval(gmp_init($decimal, 10), 16) : self::bcToHex($decimal);

        if (strlen($hex) > 32) {
            throw new OverflowException("[{$decimal}] does not fit in 128 bits.");
        }

        return strrev(hex2bin(str_pad($hex, 32, '0', STR_PAD_LEFT)));
    }

    /**
     * 16 little-endian bytes to a decimal string.
     */
    public static function fromBytes(string $bytes): string
    {
        $hex = ltrim(bin2hex(strrev($bytes)), '0') ?: '0';

        return extension_loaded('gmp') ? gmp_strval(gmp_init($hex, 16), 10) : self::bcFromHex($hex);
    }

    private static function bcToHex(string $decimal): string
    {
        $hex = '';
        do {
            $hex = dechex((int) bcmod($decimal, '16')).$hex;
            $decimal = bcdiv($decimal, '16', 0);
        } while (bccomp($decimal, '0') > 0);

        return $hex;
    }

    private static function bcFromHex(string $hex): string
    {
        $decimal = '0';
        foreach (str_split($hex) as $digit) {
            $decimal = bcadd(bcmul($decimal, '16'), (string) hexdec($digit));
        }

        return $decimal;
    }
}
