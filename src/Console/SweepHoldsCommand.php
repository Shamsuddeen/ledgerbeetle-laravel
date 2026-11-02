<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Console;

use Illuminate\Console\Command;
use LedgerBeetle\Laravel\Services\HoldService;

class SweepHoldsCommand extends Command
{
    protected $signature = 'ledger:sweep-holds {--limit=1000}';

    protected $description = 'Mark holds whose timeout has passed as expired.';

    public function handle(HoldService $holds): int
    {
        $count = $holds->sweepExpired((int) $this->option('limit'));

        $this->info("Swept {$count} hold(s).");

        return self::SUCCESS;
    }
}
