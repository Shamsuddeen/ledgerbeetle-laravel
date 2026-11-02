<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Enums;

enum TransferKind: string
{
    case TRANSFER = 'transfer';
    case REVERSAL = 'reversal';
    case HOLD_POST = 'hold_post';
    case HOLD_VOID = 'hold_void';
}
