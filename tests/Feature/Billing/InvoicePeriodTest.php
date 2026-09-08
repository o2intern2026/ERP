<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Billing\Services\InvoiceService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** Tester feedback #4: invoices for any period (week / fortnight / custom), service or storage scope, grouped by Job or by order. */
class InvoicePeriodTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_period_draft_respects_scope_and_groups_by_order_when_asked(): void
    {
        $client = $this->client(['invoice_grouping' => 'order', 'invoice_period' => 'fortnightly']);
        $finance = $this->staff('finance');
        $jobA = app(JobService::class)->create($client->id, 'loose', ['reference' => 'A'])['job_id'];
        $jobB = app(JobService::class)->create($client->id, 'loose', ['reference' => 'B'])['job_id'];
        $engine = app(ChargeEngine::class);
        // Two orders' processing fees (source order), one storage week, one charge outside the period.
        $orderIds = [];
        foreach ([[$jobA, 501], [$jobA, 502], [$jobB, 503]] as [$job, $orderId]) {
            $orderIds[] = $orderId;
            DB::table('orders')->insert(['order_no' => 'ORD-TEST-'.$orderId, 'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'source' => 'manual', 'deliver_to_name' => 'R', 'deliver_to_address' => '1 St', 'deliver_to_suburb' => 'Melb', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'requested_date' => today(), 'operational_status' => 'packed', 'fulfilment_status' => 'fulfilled', 'billing_status' => 'unbilled', 'created_at' => now(), 'updated_at' => now(), 'id' => $orderId]);
            $engine->applyEvent(['event_name' => 'outbound.packed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => ['order_id' => $orderId, 'fulfilment_id' => $orderId, 'activity_version' => 1, 'is_urgent' => false, 'label_count' => 1, 'lines' => [['unit_type' => 'carton', 'qty' => 1, 'unit_weight_kg' => 10]]]]);
        }
        $storage = $engine->manual($jobA, $client->id, 'WH-STORAGE-PLT-WK', 2, 'demo storage', null, $finance->id);
        $old = $engine->manual($jobB, $client->id, 'WH-PUTAWAY-PLT', 1, 'old', null, $finance->id);
        $old->update(['charge_date' => today()->subDays(30)]);

        $invoices = app(InvoiceService::class);
        $service = $invoices->draftPeriod($client->id, today()->subDays(13), today(), 'service'); // a fortnight, service fees only
        $this->assertSame('service', $service->invoice_type);
        $this->assertSame('order', $service->group_by); // client default
        $this->assertSame(today()->subDays(13)->toDateString(), $service->period_from->toDateString());
        $this->assertFalse($service->lines()->where('charge_code', 'WH-STORAGE-PLT-WK')->exists());
        $this->assertFalse($service->lines()->where('charge_id', $old->id)->exists());
        $this->assertEqualsCanonicalizing($orderIds, $service->lines()->whereNotNull('order_id')->distinct()->pluck('order_id')->map(fn ($v) => (int) $v)->all());
        $groups = $invoices->groupedLines($service);
        $this->assertSame(['ORD-TEST-501', 'ORD-TEST-502', 'ORD-TEST-503'], $groups->pluck('title')->all());

        $storageInvoice = $invoices->draftPeriod($client->id, today()->subDays(13), today(), 'storage', 'job');
        $this->assertSame('storage', $storageInvoice->invoice_type);
        $this->assertSame('job', $storageInvoice->group_by);
        $this->assertSame([$storage->id], $storageInvoice->lines()->pluck('charge_id')->all());

        $this->actingAs($finance)->get(route('billing.invoices.show', $service))->assertOk()->assertSee('ORD-TEST-503')->assertSee(__('billing.invoices.group_by.order'));
        $this->actingAs($finance)->get(route('billing.unbilled'))->assertOk()->assertSee(__('billing.unbilled_period.draft_period'));
        $this->actingAs($finance)->post(route('billing.invoices.draft_period'), ['client_id' => $client->id, 'from' => today()->subDays(60)->toDateString(), 'to' => today()->subDays(20)->toDateString(), 'scope' => 'all'])->assertRedirect();
        $this->assertSame(3, Invoice::query()->count()); // the "all" draft picked up the 30-day-old putaway charge
    }
}
