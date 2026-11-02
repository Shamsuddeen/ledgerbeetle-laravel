<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Enums;

enum TransferKind: string
{
    case TRANSFER = 'transfer';
    case REVERSAL = 'reversal';
    case HOLD_POST = 'hold_post';
    case HOLD_VOID = 'hold_void';
    // A leg of a currency conversion; reversed only as a whole conversion.
    case CONVERSION = 'conversion';
}
