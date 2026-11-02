<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Support;

/**
 * An account's balance as TigerBeetle reports it, in minor units.
 *
 * "posted" and "available" are signed from the account type's normal side:
 * positive means a normal balance (e.g. funds in a customer wallet).
 * "available" also subtracts outgoing holds.
 */
final readonly class Balance
{
    public function __construct(
        public string $currency,
        public int $debitsPending,
        public int $debitsPosted,
        public int $creditsPending,
        public int $creditsPosted,
        public int $posted,
        public int $available,
    ) {}

    public static function fromTotals(string $currency, bool $debitNormal, int $dp, int $dP, int $cp, int $cP): self
    {
        $posted = $debitNormal ? $dP - $cP : $cP - $dP;
        $outgoingPending = $debitNormal ? $cp : $dp;

        return new self($currency, $dp, $dP, $cp, $cP, $posted, $posted - $outgoingPending);
    }

    public function toMoney(): Money
    {
        return Money::of($this->available, $this->currency);
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
