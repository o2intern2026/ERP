<?php

namespace Tests\Feature\Platform;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Consumers\JobCostConsumer;
use App\Modules\Platform\Models\Job;
use App\Support\Contracts\JobService;
use App\Support\Outbox\ConsumerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** shipment.booked → Job cost estimated from carrier_costs; delivery.pod_captured → actual cost, confirmed once every outbound shipment is delivered. */
class JobCostConsumerTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_job_cost_rolls_up_from_carrier_costs(): void
    {
        $client = $this->client();
        $jobId = app(JobService::class)->create($client->id, 'loose', ['reference' => 'COST-1'])['job_id'];
        $carrier = Carrier::query()->create(['code' => 'MANUAL', 'name' => 'Manual carrier']);
        $shipments = [];
        foreach (['S1' => 'booked', 'S2' => 'booked'] as $no => $status) {
            $shipments[$no] = DB::table('shipments')->insertGetId(['shipment_no' => 'SHP-'.$no, 'job_id' => $jobId, 'client_id' => $client->id, 'order_id' => 1, 'shipment_type' => 'outbound', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        $registry = app(ConsumerRegistry::class);
        $this->assertContains(JobCostConsumer::class, $registry->for('shipment.booked'));
        $this->assertContains(JobCostConsumer::class, $registry->for('delivery.pod_captured'));
        $consumer = app(JobCostConsumer::class);

        // Booked before the cost row exists: the payload's expected cost is used so the estimate is never empty.
        $consumer->handle(['event_name' => 'shipment.booked', 'job_id' => $jobId, 'payload' => ['shipment_id' => $shipments['S1'], 'expected_cost_cents' => 7500]]);
        $this->assertSame(['estimated', 7500, 0], $this->cost($jobId)); // actual defaults to 0 until the first POD

        DB::table('carrier_costs')->insert([
            ['shipment_id' => $shipments['S1'], 'job_id' => $jobId, 'carrier_id' => $carrier->id, 'expected_cost_cents' => 7500, 'created_at' => now(), 'updated_at' => now()],
            ['shipment_id' => $shipments['S2'], 'job_id' => $jobId, 'carrier_id' => $carrier->id, 'expected_cost_cents' => 4000, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $consumer->handle(['event_name' => 'shipment.booked', 'job_id' => $jobId, 'payload' => ['shipment_id' => $shipments['S2'], 'expected_cost_cents' => 4000]]);
        $this->assertSame(['estimated', 11500, 0], $this->cost($jobId));

        // First POD: one shipment still open → actual known so far, status stays estimated.
        DB::table('shipments')->where('id', $shipments['S1'])->update(['status' => 'delivered']);
        DB::table('carrier_costs')->where('shipment_id', $shipments['S1'])->update(['actual_cost_cents' => 8200, 'confirmed_at' => now()]);
        $consumer->handle(['event_name' => 'delivery.pod_captured', 'job_id' => $jobId, 'payload' => ['shipment_id' => $shipments['S1']]]);
        $this->assertSame(['estimated', 11500, 12200], $this->cost($jobId));

        // Last POD → confirmed; summary margin flips from estimate to actual (client users never see cost — JobService rule).
        DB::table('shipments')->where('id', $shipments['S2'])->update(['status' => 'delivered']);
        $consumer->handle(['event_name' => 'delivery.pod_captured', 'job_id' => $jobId, 'payload' => ['shipment_id' => $shipments['S2']]]);
        $this->assertSame(['confirmed', 11500, 12200], $this->cost($jobId));
        $summary = app(JobService::class)->summarize($jobId);
        $this->assertFalse($summary['margin_is_estimate']);
        $this->assertSame(12200, $summary['actual_cost_cents']);
    }

    /** @return array{0: string, 1: int, 2: int} */
    private function cost(int $jobId): array
    {
        $job = Job::query()->findOrFail($jobId);

        return [$job->cost_status, $job->estimated_cost_cents, $job->actual_cost_cents];
    }
}
