<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * A balance as it stood right after one transfer.
 */
final readonly class BalanceSnapshot
{
    public function __construct(
        public Balance $balance,
        public CarbonImmutable $at,
        public int $tbTimestamp,
    ) {}

    /**
     * TigerBeetle timestamps are nanoseconds since the Unix epoch.
     */
    public static function timeOf(int $tbTimestamp): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', intdiv($tbTimestamp, 1_000_000_000), intdiv($tbTimestamp % 1_000_000_000, 1_000)),
        );
    }

    /**
     * The latest TB timestamp that falls within $time (inclusive).
     */
    public static function tbTimestampOf(DateTimeInterface $time): int
    {
        return $time->getTimestamp() * 1_000_000_000 + (int) $time->format('u') * 1_000 + 999;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['at' => $this->at->toIso8601ZuluString('microsecond')] + $this->balance->toArray();
    }
}
