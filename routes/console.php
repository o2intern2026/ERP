<?php

use Illuminate\Support\Facades\Schedule;

// Production is cron only (no daemons): cron runs `schedule:run` every minute and `queue:work --stop-when-empty`.
// Locally `php artisan schedule:work` stands in (AGENTS.md).
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('stock:reconcile')->dailyAt('02:00'); // ledger vs balances (ERP_PLAN §4.3 rule 9)
Schedule::command('webhooks:retry')->everyFiveMinutes()->withoutOverlapping(); // A23 per-endpoint retries
Schedule::command('billing:storage-weekly')->weeklyOn(1, '01:00'); // weekly storage from snapshots (ERP_PLAN §6.7 A6b)
Schedule::command('stock:snapshot')->dailyAt('23:55'); // storage-charge basis (ERP_PLAN §4.4 每日快照, §4.8)
Schedule::command('billing:flag-overdue')->dailyAt('06:00'); // overdue is a display flag only (ERP_PLAN §0.2 rule 9)
