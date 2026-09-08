<?php

namespace Tests\Feature;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Models\Job;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\ReturnReceipt;
use App\Modules\Warehouse\Models\StockSnapshot;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoFlowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ERP_PLAN §7 end-to-end script, executed through the modules' real services by DemoFlowSeeder (no Karrio key in tests →
 * the third-party leg uses a manual quote). Every step must report ✓ and the cross-module outcomes must hold.
 */
class DemoFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_flow_runs_end_to_end(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seeder = new DemoFlowSeeder;
        $seeder->run();
        $out = $seeder->output();
        $notes = (fn () => $this->notes)->call($seeder);
        $failed = array_values(array_filter($notes, fn ($n) => str_starts_with($n, '✗')));
        $this->assertSame([], $failed, implode("\n", $notes));

        // §7 #2–#3: real manifest → ASN → orders per consignment mark, second click creates nothing
        $this->assertGreaterThanOrEqual(25, $out['edward_orders_generated']);
        $this->assertDatabaseHas('exceptions', ['type' => 'discrepancy', 'status' => 'open']); // §4.7 #8 short-shipped line
        // Tester feedback round 3 item 2: the receiving batch was closed with 入库完成 → completed 入库单 with its PDF in the document centre
        $receipt = GoodsReceipt::query()->where('receipt_no', $out['edward_receipt_no'])->firstOrFail();
        $this->assertSame('completed', $receipt->status);
        $this->assertGreaterThan(100, $receipt->line_count);
        $this->assertDatabaseHas('documents', ['id' => $receipt->pdf_document_id, 'type' => 'goods_receipt', 'related_type' => 'asn', 'related_id' => $receipt->asn_id, 'client_visible' => true]);
        $this->assertGreaterThanOrEqual(3, GoodsReceipt::query()->where('status', 'completed')->count()); // Edward container + the loose-truck ASNs
        $this->artisan('stock:reconcile')->assertSuccessful(); // §4.7 #2

        // §7 #4–#5: the four confirmed orders travelled the whole chain
        [$a, $b, $c, $d] = array_map(fn ($id) => Order::query()->withoutGlobalScopes()->findOrFail($id), $out['edward_order_ids']);
        $this->assertSame('delivered', $a->fresh()->operational_status); // own fleet, driver POD; its return is a separate return order below
        $this->assertSame('dispatched', $b->fresh()->operational_status); // failed at the door → still out with the redelivery shipment
        $this->assertSame('delivered', $c->fresh()->operational_status); // third party + carrier POD
        $this->assertSame('packed', $d->fresh()->operational_status);    // financial hold blocks the handover
        $this->assertTrue($d->fresh()->hasActiveFinancialHold());
        $this->assertDatabaseHas('exceptions', ['type' => 'pick_short']); // §4.7 #10
        $this->assertDatabaseHas('exceptions', ['type' => 'delivery_failed']); // §5.7 #6
        $this->assertSame(2, Shipment::query()->where('order_id', $b->id)->count()); // original + redelivery
        $this->assertDatabaseHas('pods', ['shipment_id' => Shipment::query()->where('shipment_no', $out['own_fleet_shipment'])->value('id')]);

        // §7 #7: charges trace to their source
        $codes = Charge::query()->with('chargeCode')->get()->pluck('chargeCode.code')->unique();
        foreach (['WH-DEVAN-40-LOOSE', 'WH-PUTAWAY-PLT', 'WH-ORDER-DESPATCH', 'WH-PICK-CTN-GE45', 'WH-LABEL-OUT', 'TR-DELIVERY-BASE', 'TR-FUEL', 'WH-STORAGE-PLT-WK', 'WH-PALLET-RENT-POOL-WK', 'VAS-LABOUR-HR', 'VAS-LABOUR-HR-AH', 'VAS-SCAN'] as $code) {
            $this->assertTrue($codes->contains($code), "missing charge {$code}; have ".$codes->implode(', '));
        }
        $this->assertDatabaseHas('exceptions', ['type' => 'missing_rate']); // Monthly Demo has no freight card → §6.8 #9

        // §7 #4 #8 #10: invoices — service (issued, part-paid), storage week, monthly consolidated
        $service = Invoice::query()->where('invoice_no', $out['service_invoice_no'])->firstOrFail();
        $this->assertSame('part_paid', $service->status);
        $this->assertGreaterThan(0, $service->gst_cents ?? $service->tax_cents ?? 1);
        $this->assertArrayHasKey('storage_invoice_no', $out);
        $this->assertArrayHasKey('monthly_invoice_no', $out);
        $this->assertSame(9, StockSnapshot::query()->distinct('snapshot_date')->count('snapshot_date')); // §4.7 #7

        // Return chain (§3.8 #4, §4.7 #16) and Job cost (Platform consumer)
        $this->assertSame('inspected', ReturnReceipt::query()->where('receipt_no', $out['return_receipt_no'])->value('status'));
        $this->assertSame('returned', Order::query()->withoutGlobalScopes()->where('order_no', $out['return_order_no'])->value('operational_status'));
        $job = Job::query()->findOrFail($out['edward_job_id']);
        $this->assertGreaterThan(0, $job->estimated_cost_cents);
        $this->assertGreaterThan(0, DB::table('order_api_tokens')->count());
    }
}
