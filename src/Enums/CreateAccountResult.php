<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Enums;

/**
 * Result codes from TigerBeetle's tb_client.h (0.17.9). Generated, do not reorder.
 */
enum CreateAccountResult: int
{
    case CREATED = 0xFFFFFFFF;
    case LINKED_EVENT_FAILED = 1;
    case LINKED_EVENT_CHAIN_OPEN = 2;
    case IMPORTED_EVENT_EXPECTED = 22;
    case IMPORTED_EVENT_NOT_EXPECTED = 23;
    case TIMESTAMP_MUST_BE_ZERO = 3;
    case IMPORTED_EVENT_TIMESTAMP_OUT_OF_RANGE = 24;
    case IMPORTED_EVENT_TIMESTAMP_MUST_NOT_ADVANCE = 25;
    case RESERVED_FIELD = 4;
    case RESERVED_FLAG = 5;
    case ID_MUST_NOT_BE_ZERO = 6;
    case ID_MUST_NOT_BE_INT_MAX = 7;
    case EXISTS_WITH_DIFFERENT_FLAGS = 15;
    case EXISTS_WITH_DIFFERENT_USER_DATA_128 = 16;
    case EXISTS_WITH_DIFFERENT_USER_DATA_64 = 17;
    case EXISTS_WITH_DIFFERENT_USER_DATA_32 = 18;
    case EXISTS_WITH_DIFFERENT_LEDGER = 19;
    case EXISTS_WITH_DIFFERENT_CODE = 20;
    case EXISTS = 21;
    case FLAGS_ARE_MUTUALLY_EXCLUSIVE = 8;
    case DEBITS_PENDING_MUST_BE_ZERO = 9;
    case DEBITS_POSTED_MUST_BE_ZERO = 10;
    case CREDITS_PENDING_MUST_BE_ZERO = 11;
    case CREDITS_POSTED_MUST_BE_ZERO = 12;
    case LEDGER_MUST_NOT_BE_ZERO = 13;
    case CODE_MUST_NOT_BE_ZERO = 14;
    case IMPORTED_EVENT_TIMESTAMP_MUST_NOT_REGRESS = 26;

    public function isSuccess(): bool
    {
        return $this === self::CREATED || $this === self::EXISTS;
    }
}
