<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Support;

use InvalidArgumentException;
use Stringable;

/**
 * An amount in integer minor units. Parsing never goes through floats.
 */
final readonly class Money implements Stringable
{
    public function __construct(
        public int $minor,
        public string $currency,
    ) {}

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, strtoupper($currency));
    }

    /**
     * Parse "12.34" style input. Throws if it has more decimals than the
     * currency's configured scale rather than rounding silently.
     */
    public static function parse(string $amount, string $currency): self
    {
        $currency = strtoupper($currency);
        $scale = app(Ledgers::class)->scaleFor($currency);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($amount), $m)) {
            throw new InvalidArgumentException("[{$amount}] is not a decimal amount.");
        }

        [, $sign, $whole, $fraction] = $m + [3 => ''];

        if (strlen(rtrim($fraction, '0')) > $scale) {
            throw new InvalidArgumentException("[{$amount}] has more than {$scale} decimals for {$currency}.");
        }

        // Trailing zeros past the scale were allowed above; drop them.
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $digits = ltrim($whole.$fraction, '0') ?: '0';

        return new self((int) ($sign.U128::toInt($digits)), $currency);
    }

    public function toDecimal(): string
    {
        $scale = app(Ledgers::class)->scaleFor($this->currency);
        $digits = str_pad((string) abs($this->minor), $scale + 1, '0', STR_PAD_LEFT);
        $sign = $this->minor < 0 ? '-' : '';

        return $scale === 0 ? $sign.$digits : $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency;
    }
}
