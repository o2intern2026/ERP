<?php

namespace App\Modules\Warehouse\Console;

use App\Modules\Warehouse\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** Cron: nightly stock snapshot (scheduled 23:55 in routes/console.php); `--date` re-creates a past day. */
final class SnapshotStockCommand extends Command
{
    protected $signature = 'stock:snapshot {--date= : Snapshot date (YYYY-MM-DD), default today}';

    protected $description = 'Write the daily stock snapshot every storage charge is derived from';

    public function handle(SnapshotService $snapshots): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : today();
        $rows = $snapshots->take($date);
        $this->info("stock:snapshot {$date->toDateString()} — {$rows} unit rows");

        return self::SUCCESS;
    }
}
