<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Services\InvoiceService;
use Illuminate\Console\Command;

/** Cron (daily): mark issued invoices past due_at as overdue — display only, never blocks bookings (§0.2 rule 9). */
final class FlagOverdueInvoicesCommand extends Command
{
    protected $signature = 'billing:flag-overdue';

    protected $description = 'Flag issued invoices past their due date';

    public function handle(InvoiceService $invoices): int
    {
        $this->info('billing:flag-overdue — '.$invoices->flagOverdue().' invoice(s) flagged');

        return self::SUCCESS;
    }
}
