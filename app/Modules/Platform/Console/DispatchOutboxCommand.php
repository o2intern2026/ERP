<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Services\OutboxDispatcher;
use Illuminate\Console\Command;

/** Cron entry point for the outbox (scheduled every minute in routes/console.php). */
final class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100 : Maximum events per run}';

    protected $description = 'Deliver due outbox events to their consumers (A31)';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $counts = $dispatcher->dispatchDue((int) $this->option('limit'));

        $this->info(sprintf('outbox: published %d, failed %d, dead %d', $counts['published'], $counts['failed'], $counts['dead']));

        return self::SUCCESS;
    }
}
