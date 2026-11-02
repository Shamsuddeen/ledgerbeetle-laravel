<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Enums;

use LedgerBeetle\Laravel\Support\Flags;

enum AccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case REVENUE = 'revenue';
    case EXPENSE = 'expense';

    /**
     * TigerBeetle account `code` for this type.
     */
    public function tbCode(): int
    {
        return match ($this) {
            self::ASSET => 1,
            self::LIABILITY => 2,
            self::EQUITY => 3,
            self::REVENUE => 4,
            self::EXPENSE => 5,
        };
    }

    public function isDebitNormal(): bool
    {
        return $this === self::ASSET || $this === self::EXPENSE;
    }

    /**
     * The TB flag that stops the balance going negative on this type's normal side.
     */
    public function overdraftFlag(): int
    {
        return $this->isDebitNormal()
            ? Flags::ACCOUNT_CREDITS_MUST_NOT_EXCEED_DEBITS
            : Flags::ACCOUNT_DEBITS_MUST_NOT_EXCEED_CREDITS;
    }
}
