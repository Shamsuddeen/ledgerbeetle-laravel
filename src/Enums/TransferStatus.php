<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Enums;

enum TransferStatus: string
{
    // Recorded locally; TigerBeetle has not confirmed it yet.
    case PENDING = 'pending';
    case POSTED = 'posted';
    case FAILED = 'failed';
}
