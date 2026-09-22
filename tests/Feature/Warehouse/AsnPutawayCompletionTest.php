<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-03: PutawayService treated a line received with 0 cartons ("not on truck") as still pending, so a short-shipped
 * ASN never reached `putaway` and never emitted asn.putaway_completed (no putaway / label charges, no 生成派送订单). The completion rule is
 * now the 入库单's (a line counts as received once it has a receipt line or stock units), it also runs when a line is received with nothing
 * to put away and at 入库完成, and `asn:reevaluate-putaway` re-checks ASNs already stuck.
 */
class AsnPutawayCompletionTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** @return array{Asn, AsnLine, AsnLine, Warehouse} */
    private function twoLineAsn(): array
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'SHORT1', 'size' => '40', 'unpack_mode' => 'loose']]]);
        [$a, $b] = app(AsnService::class)->addLines($asn, [['container_no' => 'SHORT1', 'description' => 'Arrived', 'expected_cartons' => 10], ['container_no' => 'SHORT1', 'description' => 'Missing', 'expected_cartons' => 3]]);

        return [$asn, $a, $b, $warehouse];
    }

    private function putawayEvents(Asn $asn): int
    {
        return OutboxEvent::query()->where('event_name', 'asn.putaway_completed')->where('job_id', $asn->job_id)->count();
    }

    public function test_a_line_received_as_zero_does_not_block_completion_when_the_other_units_are_put_away(): void
    {
        [$asn, $a, $b, $warehouse] = $this->twoLineAsn();
        $receiving = $this->location($warehouse, 'receiving');
        [$unit] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 400, 'pallet_source' => 'warehouse_plain']]], $receiving);
        $this->assertSame([], app(ReceivingService::class)->receiveLine($b, ['received_cartons' => 0, 'variance_reason' => 'not on truck', 'units' => []], $receiving));
        $this->assertSame('receiving', $asn->fresh()->status, 'the pallet is still in the receiving area');
        $this->assertSame(0, $this->putawayEvents($asn));

        app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));

        $asn = $asn->fresh();
        $this->assertSame('putaway', $asn->status);
        $this->assertNotNull($asn->putaway_completed_at);
        $this->assertSame(1, $this->putawayEvents($asn));
        $event = OutboxEvent::query()->where('event_name', 'asn.putaway_completed')->where('job_id', $asn->job_id)->firstOrFail();
        $this->assertSame(1, $event->payload['pallet_count']);
        $this->assertEquals([['asn_line_id' => $a->id, 'expected_cartons' => 10, 'received_cartons' => 10, 'damaged_cartons' => 0], ['asn_line_id' => $b->id, 'expected_cartons' => 3, 'received_cartons' => 0, 'damaged_cartons' => 0]], $event->payload['lines']);
    }

    public function test_an_unreceived_line_still_holds_the_asn_and_the_zero_receipt_after_the_last_putaway_completes_it(): void
    {
        [$asn, $a, $b, $warehouse] = $this->twoLineAsn();
        $receiving = $this->location($warehouse, 'receiving');
        [$unit] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 10, 'units' => [['unit_type' => 'carton', 'carton_qty' => 10]]], $receiving);
        app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));
        $this->assertSame('receiving', $asn->fresh()->status, 'line B has not been received at all — the ASN waits');
        $this->assertSame(0, $this->putawayEvents($asn));

        // The verifier's gap: the 0-line arrives AFTER the last putaway; nothing else would re-check the ASN, so receiving it completes the ASN.
        app(ReceivingService::class)->receiveLine($b, ['received_cartons' => 0, 'variance_reason' => 'not on truck', 'units' => []], $receiving);
        $this->assertSame('putaway', $asn->fresh()->status);
        $this->assertSame(1, $this->putawayEvents($asn));

        // 入库完成 afterwards does not emit a second event.
        config(['erp.pdf_cjk_font' => storage_path('fonts/cjk.ttf')]);
        app(GoodsReceiptService::class)->complete(GoodsReceipt::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->where('status', 'open')->firstOrFail());
        $this->assertSame(1, $this->putawayEvents($asn));
        $this->assertNotNull($asn->fresh()->receiving_completed_at);
    }

    public function test_receipt_completion_and_the_one_off_command_complete_an_asn_stuck_before_the_fix(): void
    {
        config(['erp.pdf_cjk_font' => storage_path('fonts/cjk.ttf')]);
        [$asn, $a, $b, $warehouse] = $this->twoLineAsn();
        $receiving = $this->location($warehouse, 'receiving');
        app(ReceivingService::class)->receiveLine($b, ['received_cartons' => 0, 'variance_reason' => 'not on truck', 'units' => []], $receiving);
        [$unit] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 10, 'units' => [['unit_type' => 'carton', 'carton_qty' => 10]]], $receiving);
        app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));
        $this->assertSame('putaway', $asn->fresh()->status);
        // What the old rule left behind on the server: everything received and put away, status still `receiving`, no event.
        Asn::query()->whereKey($asn->id)->update(['status' => 'receiving', 'putaway_completed_at' => null]);
        OutboxEvent::query()->where('event_name', 'asn.putaway_completed')->where('job_id', $asn->job_id)->delete(); // simulating pre-fix data only

        // 入库完成 on the open batch re-checks the ASN.
        app(GoodsReceiptService::class)->complete(GoodsReceipt::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->where('status', 'open')->firstOrFail());
        $this->assertSame('putaway', $asn->fresh()->status);
        $this->assertSame(1, $this->putawayEvents($asn));

        // The command: lists the stuck ASN, completes it with --apply, then finds nothing.
        Asn::query()->whereKey($asn->id)->update(['status' => 'receiving', 'putaway_completed_at' => null]);
        $this->artisan('asn:reevaluate-putaway')->expectsOutputToContain($asn->asn_no)->expectsOutputToContain('re-run with --apply')->assertSuccessful();
        $this->assertSame('receiving', $asn->fresh()->status, 'without --apply the command only lists');
        $this->artisan('asn:reevaluate-putaway', ['--apply' => true])->expectsOutputToContain("{$asn->asn_no} completed")->assertSuccessful();
        $this->assertSame('putaway', $asn->fresh()->status);
        $this->assertSame(2, $this->putawayEvents($asn));
        $this->artisan('asn:reevaluate-putaway')->expectsOutputToContain('no ASN is stuck')->assertSuccessful();

        // An ASN with a line still unreceived is not "stuck" and is left alone.
        $client = $this->client();
        $open = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$x, $y] = app(AsnService::class)->addLines($open, [['description' => 'X', 'expected_cartons' => 1], ['description' => 'Y', 'expected_cartons' => 1]]);
        [$xu] = app(ReceivingService::class)->receiveLine($x, ['received_cartons' => 1, 'units' => [['unit_type' => 'carton', 'carton_qty' => 1]]], $receiving);
        app(PutawayService::class)->putaway($xu, $this->location($warehouse, 'storage'));
        $this->artisan('asn:reevaluate-putaway', ['--apply' => true])->expectsOutputToContain('no ASN is stuck')->assertSuccessful();
        $this->assertSame('receiving', $open->fresh()->status);
    }
}
