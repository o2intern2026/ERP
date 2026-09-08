<?php

namespace App\Modules\Reports\Console;

use App\Modules\Reports\Services\ClientReportMailer;
use App\Modules\Reports\Services\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Cron: the 1st at 07:30 Australia/Melbourne mails last month's client reports; `--month=YYYY-MM-DD` (any day of that month) re-runs a past month idempotently. */
final class SendClientMonthlyReportsCommand extends Command
{
    protected $signature = 'reports:client-monthly {--month= : Any date inside the month to report (default: last month)} {--client= : Only this client id} {--force : Send again even if this month was already sent}';

    protected $description = 'E-mail every active client its monthly report — A21 client view, customer figures only (A22)';

    public function handle(ClientReportMailer $mailer): int
    {
        $period = $this->option('month') ? ReportPeriod::monthOf(CarbonImmutable::parse($this->option('month'))) : ReportPeriod::lastMonth();
        $stats = $mailer->send('monthly', $period, (bool) $this->option('force'), $this->option('client') ? (int) $this->option('client') : null);
        $this->info(__('reports.commands.summary', ['frequency' => 'monthly', 'from' => $period->from->toDateString(), 'to' => $period->to->toDateString()] + $stats));

        return self::SUCCESS;
    }
}
