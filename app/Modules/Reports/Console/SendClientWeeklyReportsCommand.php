<?php

namespace App\Modules\Reports\Console;

use App\Modules\Reports\Services\ClientReportMailer;
use App\Modules\Reports\Services\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Cron: Monday 07:00 Australia/Melbourne mails last week's client reports; `--week=YYYY-MM-DD` (any day of that week) re-runs a past week idempotently. */
final class SendClientWeeklyReportsCommand extends Command
{
    protected $signature = 'reports:client-weekly {--week= : Any date inside the ISO week to report (default: last week)} {--client= : Only this client id} {--force : Send again even if this week was already sent}';

    protected $description = 'E-mail every active client its weekly report — A21 client view, customer figures only (A22)';

    public function handle(ClientReportMailer $mailer): int
    {
        $period = $this->option('week') ? ReportPeriod::weekOf(CarbonImmutable::parse($this->option('week'))) : ReportPeriod::lastWeek();
        $stats = $mailer->send('weekly', $period, (bool) $this->option('force'), $this->option('client') ? (int) $this->option('client') : null);
        $this->info(__('reports.commands.summary', ['frequency' => 'weekly', 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()] + $stats));

        return self::SUCCESS;
    }
}
