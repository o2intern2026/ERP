<?php

use Illuminate\Support\Facades\Schedule;

// Production is cron only (no daemons): cron runs `schedule:run` every minute and `queue:work --stop-when-empty`.
// Locally `php artisan schedule:work` stands in (AGENTS.md).
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();
