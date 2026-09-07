<?php

use Illuminate\Support\Facades\Schedule;

// Production is cron only (no daemons): cron runs `schedule:run` every minute and `queue:work --stop-when-empty`.
// Locally `php artisan schedule:work` stands in (AGENTS.md).
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('stock:reconcile')->dailyAt('02:00'); // ledger vs balances (ERP_PLAN §4.3 rule 9)
Schedule::command('stock:snapshot')->dailyAt('23:55'); // storage-charge basis (ERP_PLAN §4.4 每日快照, §4.8)
