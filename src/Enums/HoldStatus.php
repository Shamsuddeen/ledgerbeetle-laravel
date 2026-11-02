<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Enums;

enum HoldStatus: string
{
    // Recorded locally; TigerBeetle has not confirmed it yet.
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case POSTED = 'posted';
    case VOIDED = 'voided';
    case EXPIRED = 'expired';
    case FAILED = 'failed';

    public function isSettled(): bool
    {
        return in_array($this, [self::POSTED, self::VOIDED, self::EXPIRED, self::FAILED], true);
    }
}
