<?php

namespace App\Modules\Platform\Consumers;

use App\Modules\Platform\Models\Job;
use App\Support\Outbox\EventConsumer;
use Illuminate\Support\Facades\DB;

/**
 * invoice.issued → Job revenue: estimated = every live charge, actual = invoiced charges, revenue_status
 * unbilled → partially_invoiced → invoiced (paid is set by Billing's payments). Reads Billing's `charges` read-only.
 */
final class JobRevenueConsumer implements EventConsumer
{
    public function handle(array $envelope): void
    {
        foreach ($envelope['payload']['job_ids'] ?? [] as $jobId) {
            $rows = DB::table('charges')->where('job_id', (int) $jobId)->where('status', '!=', 'reversed')->whereNull('reversal_of_charge_id')->get(['status', 'amount_cents']);
            $estimated = (int) $rows->sum('amount_cents');
            $invoiced = (int) $rows->whereIn('status', ['invoiced', 'paid'])->sum('amount_cents');
            $open = $rows->whereNotIn('status', ['invoiced', 'paid'])->count();
            Job::query()->whereKey((int) $jobId)->update([
                'estimated_revenue_cents' => $estimated,
                'actual_revenue_cents' => $invoiced,
                'revenue_status' => DB::raw("IF(revenue_status = 'paid', 'paid', '".($open === 0 && $invoiced > 0 ? 'invoiced' : 'partially_invoiced')."')"),
            ]);
        }
    }
}
