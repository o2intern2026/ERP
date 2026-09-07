<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\StorageBillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** Cron: Monday 01:00 bills the week that just ended; `--week=YYYY-MM-DD` (any day of the week) re-runs a past week idempotently. */
final class BillStorageWeeklyCommand extends Command
{
    protected $signature = 'billing:storage-weekly {--week= : Any date inside the week to bill (default: last week)}';

    protected $description = 'Create weekly storage, pallet rental and pickface charges from the daily snapshots (A6b)';

    public function handle(StorageBillingService $storage): int
    {
        $day = $this->option('week') ? Carbon::parse($this->option('week')) : today()->subWeek();
        $charges = $storage->billWeek($day);
        $this->info(sprintf('billing:storage-weekly %s — %d charge(s)', $day->startOfWeek()->toDateString(), count($charges)));

        return self::SUCCESS;
    }
}
