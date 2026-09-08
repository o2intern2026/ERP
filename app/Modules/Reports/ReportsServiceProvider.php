<?php

namespace App\Modules\Reports;

use App\Modules\Reports\Console\SendClientMonthlyReportsCommand;
use App\Modules\Reports\Console\SendClientWeeklyReportsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'reports');
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        // A22: scheduled client reports — cron `schedule:run` in production, `schedule:work` locally (AGENTS.md); registered here, not in routes/console.php.
        $this->commands([SendClientWeeklyReportsCommand::class, SendClientMonthlyReportsCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('reports:client-weekly')->weeklyOn(1, '07:00')->timezone('Australia/Melbourne')->withoutOverlapping();
            $schedule->command('reports:client-monthly')->monthlyOn(1, '07:30')->timezone('Australia/Melbourne')->withoutOverlapping();
        });
    }
}
