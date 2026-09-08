<?php

namespace App\Modules\Platform\Consumers;

use App\Modules\Platform\Models\Job;
use App\Support\Outbox\EventConsumer;
use Illuminate\Support\Facades\DB;

/**
 * shipment.booked → Job cost `estimated`; delivery.pod_captured → Job actual cost, `confirmed` once every outbound
 * shipment of the Job is delivered (contracts/events.md matrix; ERP_PLAN §1.6 Job profit line). Reads Transport's
 * `carrier_costs` / `shipments` read-only — Transport owns the numbers, Platform only rolls them up onto the Job
 * (X2's HANDOFF note, M5). Replay-safe: every run recomputes from the tables.
 */
final class JobCostConsumer implements EventConsumer
{
    public function handle(array $envelope): void
    {
        $jobId = (int) ($envelope['job_id'] ?? $envelope['payload']['job_id'] ?? 0);
        if ($jobId === 0) {
            return;
        }

        $costs = DB::table('carrier_costs')->where('job_id', $jobId)->get(['expected_cost_cents', 'actual_cost_cents']);
        $estimated = (int) $costs->sum('expected_cost_cents');
        if ($costs->isEmpty() && $envelope['event_name'] === 'shipment.booked') {
            $estimated = (int) ($envelope['payload']['expected_cost_cents'] ?? 0); // booked before the cost row landed — never leave the estimate empty
        }

        $update = ['estimated_cost_cents' => $estimated];

        if ($envelope['event_name'] === 'delivery.pod_captured') {
            $update['actual_cost_cents'] = (int) $costs->sum(fn ($c) => $c->actual_cost_cents ?? $c->expected_cost_cents);
            $outstanding = DB::table('shipments')->where('job_id', $jobId)->where('shipment_type', 'outbound')->whereNotIn('status', ['delivered', 'booking_cancelled'])->exists();
            $update['cost_status'] = $outstanding ? 'estimated' : 'confirmed';
        } else {
            $update['cost_status'] = DB::raw("IF(cost_status = 'confirmed', 'confirmed', 'estimated')");
        }

        Job::query()->whereKey($jobId)->update($update);
    }
}
