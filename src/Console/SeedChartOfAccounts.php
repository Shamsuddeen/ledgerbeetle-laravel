<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Console;

use Illuminate\Console\Command;
use LedgerBeetle\Laravel\Enums\AccountType;
use LedgerBeetle\Laravel\Services\AccountService;

class SeedChartOfAccounts extends Command
{
    protected $signature = 'ledger:seed-accounts {--currency= : Currency for the accounts (default: ledger.default_currency)}';

    protected $description = 'Create a starter chart of accounts. Safe to run more than once.';

    /**
     * code => [name, type, parent code]. Codes get a ".{CURRENCY}" suffix.
     *
     * @var array<string, array{0: string, 1: AccountType, 2?: string}>
     */
    public const CHART = [
        'ASSET.CASH' => ['Cash and cash equivalents', AccountType::ASSET],
        'ASSET.CASH.OPERATING' => ['Operating account', AccountType::ASSET, 'ASSET.CASH'],
        'ASSET.CASH.RESERVE' => ['Reserve account', AccountType::ASSET, 'ASSET.CASH'],
        'ASSET.RECEIVABLE' => ['Accounts receivable', AccountType::ASSET],
        'LIABILITY.PAYABLE' => ['Accounts payable', AccountType::LIABILITY],
        'LIABILITY.CUSTOMER_FUNDS' => ['Customer funds', AccountType::LIABILITY],
        'EQUITY.CAPITAL' => ['Capital', AccountType::EQUITY],
        'REVENUE.FEES' => ['Fee revenue', AccountType::REVENUE],
        'REVENUE.INTEREST' => ['Interest revenue', AccountType::REVENUE],
        'EXPENSE.OPERATING' => ['Operating expenses', AccountType::EXPENSE],
    ];

    public function handle(AccountService $accounts): int
    {
        $currency = strtoupper($this->option('currency') ?: config('ledger.default_currency'));

        foreach (self::CHART as $code => $spec) {
            $account = $accounts->create(
                code: "{$code}.{$currency}",
                name: $spec[0],
                type: $spec[1],
                currency: $currency,
                parent: isset($spec[2]) ? "{$spec[2]}.{$currency}" : null,
            );

            $this->line("  <info>✓</info> {$account->code}");
        }

        $this->info('Chart of accounts ready.');

        return self::SUCCESS;
    }
}
