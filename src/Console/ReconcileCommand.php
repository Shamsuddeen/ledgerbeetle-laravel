<?php

declare(strict_types=1);

namespace LedgerBeetle\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use LedgerBeetle\Laravel\Contracts\TigerBeetleGateway;
use LedgerBeetle\Laravel\Enums\HoldStatus;
use LedgerBeetle\Laravel\Enums\TransferKind;
use LedgerBeetle\Laravel\Enums\TransferStatus;
use LedgerBeetle\Laravel\Models\LedgerAccount;
use LedgerBeetle\Laravel\Models\LedgerConversion;
use LedgerBeetle\Laravel\Models\LedgerHold;
use LedgerBeetle\Laravel\Models\LedgerTransfer;
use LedgerBeetle\Laravel\Services\AccountService;

/**
 * Compares local records with TigerBeetle.
 *
 * - Accounts missing in TB (created locally, crashed before TB): --fix creates them.
 * - Transfers and holds stuck in "pending" (crashed between steps): if TB has
 *   them they are marked done; if not, --fix marks them failed so a retry
 *   with the same key reports the failure instead of hanging.
 * - Conversions stuck in "pending" follow their legs.
 * - --deep also checks every posted transfer exists in TB with the same amount.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'ledger:reconcile
        {--fix : Repair what can be repaired}
        {--deep : Also verify every posted transfer}
        {--age=60 : Only touch pending rows older than this many seconds}';

    protected $description = 'Check local ledger records against TigerBeetle.';

    /** TB accepts up to 8189 events per request; stay well below. */
    private const CHUNK = 1000;

    private int $problems = 0;

    private int $unfixed = 0;

    public function handle(TigerBeetleGateway $gateway, AccountService $accounts): int
    {
        // Artisan may reuse the command instance.
        $this->problems = $this->unfixed = 0;
        $fix = (bool) $this->option('fix');
        $cutoff = Carbon::now()->subSeconds((int) $this->option('age'));

        LedgerAccount::query()->chunkById(self::CHUNK, function ($rows) use ($gateway, $accounts, $fix) {
            $found = $gateway->lookupAccounts($rows->pluck('tb_id')->all());

            foreach ($rows as $account) {
                if ($found[$account->tb_id] === null) {
                    $this->problem("Account {$account->code} is missing in TigerBeetle", $fix);
                    if ($fix) {
                        $accounts->ensureInTigerBeetle($account);
                    }
                }
            }
        });

        $this->eachInTb(LedgerHold::query()->where('status', HoldStatus::PENDING)->where('created_at', '<=', $cutoff), $gateway,
            function (LedgerHold $hold, $state) use ($fix) {
                if ($state !== null) {
                    $hold->update(['status' => HoldStatus::ACTIVE]);
                    $this->line("  Hold {$hold->id} confirmed in TigerBeetle.");

                    return;
                }

                $this->problem("Hold {$hold->id} never reached TigerBeetle", $fix);
                if ($fix) {
                    $hold->update(['status' => HoldStatus::FAILED, 'failure_reason' => 'NOT_IN_TIGERBEETLE']);
                }
            });

        $this->eachInTb(LedgerTransfer::query()->where('status', TransferStatus::PENDING)->where('created_at', '<=', $cutoff), $gateway,
            function (LedgerTransfer $transfer, $state) use ($fix) {
                if ($state !== null) {
                    $transfer->update(['status' => TransferStatus::POSTED, 'tb_timestamp' => $state->timestamp]);
                    $this->settleHold($transfer);
                    $this->line("  Transfer {$transfer->id} confirmed in TigerBeetle.");

                    return;
                }

                $this->problem("Transfer {$transfer->id} never reached TigerBeetle", $fix);
                if ($fix) {
                    $transfer->update(['status' => TransferStatus::FAILED, 'failure_reason' => 'NOT_IN_TIGERBEETLE']);
                    if ($transfer->reverses_id !== null) {
                        LedgerTransfer::query()->whereKey($transfer->reverses_id)->decrement('reversed_amount', $transfer->amount);
                    }
                }
            });

        // A conversion settles with its legs, which were reconciled above.
        LedgerConversion::query()->where('status', TransferStatus::PENDING)->where('created_at', '<=', $cutoff)
            ->with('legs')->chunkById(self::CHUNK, function ($conversions) use ($fix) {
                foreach ($conversions as $conversion) {
                    $statuses = $conversion->legs->pluck('status')->unique();

                    if ($statuses->count() === 1 && $statuses->first() === TransferStatus::POSTED) {
                        $conversion->update(['status' => TransferStatus::POSTED]);
                        $this->line("  Conversion {$conversion->id} confirmed in TigerBeetle.");
                    } elseif ($conversion->legs->isEmpty() || $statuses->contains(TransferStatus::FAILED)) {
                        $this->problem("Conversion {$conversion->id} did not post", $fix);
                        if ($fix) {
                            $failed = $conversion->legs->firstWhere('status', TransferStatus::FAILED);
                            $conversion->update([
                                'status' => TransferStatus::FAILED,
                                'failure_reason' => $failed->failure_reason ?? 'NOT_IN_TIGERBEETLE',
                                'reverses_id' => null,
                            ]);
                        }
                    }
                }
            });

        if ($this->option('deep')) {
            $this->eachInTb(LedgerTransfer::query()->where('status', TransferStatus::POSTED), $gateway,
                function (LedgerTransfer $transfer, $state) {
                    if ($state === null) {
                        $this->problem("Posted transfer {$transfer->id} is missing in TigerBeetle", false);
                    } elseif ($state->amount !== $transfer->amount) {
                        $this->problem("Transfer {$transfer->id} is {$transfer->amount} locally but {$state->amount} in TigerBeetle", false);
                    }
                });
        }

        if ($this->problems === 0) {
            $this->info('Ledger is in sync with TigerBeetle.');

            return self::SUCCESS;
        }

        $fixed = $this->problems - $this->unfixed;
        $this->warn("{$this->problems} problem(s) found, {$fixed} fixed.".($fix ? '' : ' Run with --fix to repair.'));

        return $this->unfixed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  Builder<LedgerTransfer>|Builder<LedgerHold>  $query
     * @param  callable(LedgerTransfer|LedgerHold, \LedgerBeetle\Laravel\Gateways\Data\TransferState|null): void  $check
     */
    private function eachInTb(Builder $query, TigerBeetleGateway $gateway, callable $check): void
    {
        $query->chunkById(self::CHUNK, function ($rows) use ($gateway, $check) {
            $found = $gateway->lookupTransfers($rows->pluck('tb_id')->all());

            foreach ($rows as $row) {
                $check($row, $found[$row->tb_id]);
            }
        });
    }

    private function settleHold(LedgerTransfer $transfer): void
    {
        $hold = $transfer->hold;

        if ($hold === null || $hold->status !== HoldStatus::ACTIVE) {
            return;
        }

        $hold->update($transfer->kind === TransferKind::HOLD_POST
            ? ['status' => HoldStatus::POSTED, 'posted_amount' => $transfer->amount]
            : ['status' => HoldStatus::VOIDED]);
    }

    private function problem(string $message, bool $fixing): void
    {
        $this->problems++;
        $this->unfixed += $fixing ? 0 : 1;
        $this->line('  <comment>!</comment> '.$message.($fixing ? ' <info>(fixed)</info>' : ''));
    }
}
