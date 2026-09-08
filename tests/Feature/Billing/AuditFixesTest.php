<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Billing\Services\RateCardService;
use App\Modules\Platform\Models\Job;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\TaskService;
use App\Support\Contracts\JobService;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** Acceptance-audit fixes (docs/acceptance/2026-09-08): extra charges bill, unload only for LCL trucks, invoice.issued, per_job draft, rate item audit. */
class AuditFixesTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_delivery_extra_charges_bill_waiting_hours_failed_and_redelivery_once(): void
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only')['job_id'];
        $codes = ChargeCode::query()->pluck('id', 'code');
        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'extras', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        foreach (['TR-WAITING' => 2000, 'TR-FAILED' => 3500, 'TR-REDELIVERY' => 5000] as $code => $rate) {
            RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes[$code], 'pricing_mode' => 'fixed', 'rate_cents' => $rate]);
        }
        $base = ['shipment_id' => 31, 'shipment_no' => 'SHP-31', 'job_id' => $job, 'client_id' => $client->id, 'order_id' => 9, 'uom' => 'delivery', 'cost_cents' => null, 'note' => 'demo', 'reported_by' => 1];
        $publish = fn (array $extra) => DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent($base + $extra, 'delivery.extra_charge', $job, $client->id)));
        $publish(['charge_type' => 'waiting', 'qty' => 1.5, 'uom' => 'man_hour', 'occurred_at' => '2026-09-08T10:00:00+10:00']);
        $publish(['charge_type' => 'failed', 'qty' => 1, 'occurred_at' => '2026-09-08T11:00:00+10:00']);
        $publish(['charge_type' => 'redelivery', 'qty' => 1, 'occurred_at' => '2026-09-08T12:00:00+10:00']);
        $publish(['charge_type' => 'failed', 'qty' => 1, 'occurred_at' => '2026-09-08T11:00:00+10:00']); // same report replayed
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();

        $byCode = Charge::query()->with('chargeCode')->get()->groupBy('chargeCode.code')->map(fn ($c) => [$c->count(), (int) $c->sum('amount_cents')]);
        $this->assertEquals(['TR-WAITING' => [1, 3000], 'TR-FAILED' => [1, 3500], 'TR-REDELIVERY' => [1, 5000]], $byCode->all()); // §5.7 #6
    }

    public function test_truck_unload_fee_only_for_loose_truck_receiving_tasks(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $tasks = app(TaskService::class);
        $container = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'UNL1', 'size' => '40', 'unpack_mode' => 'pallet']]]);
        $truck = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        foreach ([$container, $truck] as $asn) {
            $task = $tasks->create('receiving', ['job_id' => $asn->job_id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'asn', 'source_id' => $asn->id, 'asn_id' => $asn->id]);
            $tasks->complete($task, ['billable_qty' => 6, 'billable_uom' => 'pallet'], $asn->asn_no);
        }
        app(OutboxDispatcher::class)->dispatchDue();

        $unload = Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-UNLOAD-PLT'))->get();
        $this->assertCount(1, $unload); // §4.7 #23: the container's cartons come off in devanning, not as an LCL unload
        $this->assertSame($truck->job_id, $unload->first()->job_id);
        $this->assertSame(2400, $unload->first()->amount_cents);
    }

    public function test_issuing_an_invoice_emits_invoice_issued_and_rolls_revenue_onto_the_job(): void
    {
        $client = $this->client();
        $jobId = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $engine = app(ChargeEngine::class);
        $engine->manual($jobId, $client->id, 'WH-PUTAWAY-PLT', 2, 'demo', null, $this->staff('finance')->id);
        $engine->manual($jobId, $client->id, 'WH-LABEL-IN', 10, 'demo', null, $this->staff('finance')->id);
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($invoices->draftForJob($jobId));

        $event = OutboxEvent::query()->where('event_name', 'invoice.issued')->firstOrFail();
        $this->assertSame([$jobId], $event->payload['job_ids']);
        $this->assertSame($invoice->invoice_no, $event->payload['invoice_no']);
        $this->assertSame($invoice->total_cents, $event->payload['total_cents']);
        app(OutboxDispatcher::class)->dispatchDue();
        $job = Job::query()->findOrFail($jobId);
        $this->assertSame('invoiced', $job->revenue_status);
        $this->assertSame((int) $invoice->subtotal_cents, $job->actual_revenue_cents); // ex GST, like the charges
        $this->assertSame($job->estimated_revenue_cents, $job->actual_revenue_cents);
    }

    public function test_per_job_client_gets_a_service_invoice_draft_when_the_final_quote_is_confirmed(): void
    {
        $perJob = $this->client(['default_markup_percent' => 20, 'invoice_mode' => 'per_job']);
        $monthly = $this->client(['code' => 'MNTH', 'invoice_mode' => 'monthly']);
        $codes = ChargeCode::query()->pluck('id', 'code');
        foreach ([$perJob, $monthly] as $client) {
            $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'freight', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
            RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'cost_plus']);
            $job = app(JobService::class)->create($client->id, 'transport_only')['job_id'];
            DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([
                'shipment_id' => 100 + $client->id, 'shipment_no' => 'SHP-'.$client->id, 'shipment_type' => 'outbound', 'order_id' => 50 + $client->id, 'client_id' => $client->id, 'job_id' => $job, 'source' => 'manual', 'pricing_mode' => 'cost_plus', 'quote_stage' => 'final',
                'cost_cents' => 10000, 'customer_price_cents' => 12000, 'tailgate_required' => false, 'zone' => 'metro', 'packages' => ['count' => 1, 'total_weight_kg' => 10, 'total_cbm' => 0.1], 'confirmed_by_type' => 'client',
            ], 'shipment.quote_confirmed', $job, $client->id)));
        }
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();

        $drafts = Invoice::query()->withoutGlobalScopes()->where('status', 'draft')->get();
        $this->assertCount(1, $drafts); // §6.8 #12: per_job → draft now; monthly → un-invoiced pool
        $this->assertSame($perJob->id, $drafts->first()->client_id);
        $this->assertSame('service', $drafts->first()->invoice_type);
        $this->assertSame(12000, (int) $drafts->first()->lines()->sum('amount_cents'));
    }

    public function test_rate_item_edits_are_audited(): void
    {
        $client = $this->client();
        $admin = $this->staff('admin');
        $rates = app(RateCardService::class);
        $card = $rates->createClientCard($client, $admin, today(), 'audit');
        $item = $rates->addItem($card, ['charge_code_id' => ChargeCode::query()->where('code', 'WH-PUTAWAY-PLT')->value('id'), 'pricing_mode' => 'fixed', 'rate_cents' => 450]);
        $this->actingAs($admin);
        $rates->updateItem($item, ['rate_cents' => 500]);

        $log = Activity::query()->where('subject_type', RateItem::class)->where('subject_id', $item->id)->where('event', 'updated')->latest('id')->firstOrFail(); // §2.5 #4
        $this->assertSame(500, $log->properties['attributes']['rate_cents']);
        $this->assertSame(450, $log->properties['old']['rate_cents']);
        $this->assertSame($admin->id, $log->causer_id);
    }
}
