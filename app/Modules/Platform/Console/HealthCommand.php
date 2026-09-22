<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Services\HealthCheck;
use Illuminate\Console\Command;

/**
 * CR #137 (audit ADMIN-12): `php artisan erp:health` — the same facts as the admin banner, for cron or an external monitor.
 * Exit 0 when the outbox backlog is fresh, 1 when the oldest pending event is older than erp.outbox_stale_minutes (or dead
 * events wait for a human), so a monitor can alert on the exit code; --json for machines.
 */
class HealthCommand extends Command
{
    protected $signature = 'erp:health {--json : print the facts as JSON}';

    protected $description = 'Background-work health: pending outbox events, oldest age, failed / dead, last stock snapshot, queue size';

    public function handle(HealthCheck $health): int
    {
        $facts = $health->facts();

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf('pending events:        %d', $facts['pending']));
            $this->line(sprintf('oldest pending age:    %s', $facts['oldest_pending_minutes'] === null ? '-' : $facts['oldest_pending_minutes'].' min (stale after '.$facts['stale_after_minutes'].')'));
            $this->line(sprintf('failed (will retry):   %d', $facts['failed']));
            $this->line(sprintf('dead (needs a human):  %d', $facts['dead']));
            $this->line(sprintf('last stock snapshot:   %s', $facts['last_snapshot_date'] ?? '-'));
            $this->line(sprintf('queue size:            %d', $facts['queue_size']));
            $this->line($facts['stale'] ? 'STATUS: STALE — outbox backlog is not moving; check cron (schedule:run)' : ($facts['dead'] > 0 ? 'STATUS: DEAD EVENTS — see /admin/integration' : 'STATUS: OK'));
        }

        return $facts['stale'] || $facts['dead'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
