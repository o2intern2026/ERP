<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Services\ReturnService;
use App\Support\Contracts\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B13 (§4.7 #16): a return changes stock only after inspection; available → unit in receiving (put away next), quarantine / damaged → held in QA. */
class ReturnReceiptTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_return_is_received_then_inspected_and_only_inspection_adds_stock(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'R1', 'cartons' => 10], ['mark' => 'R1', 'cartons' => 6]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 4], ['asn_line_id' => $asnLines[1]->id, 'qty' => 2]]);
        $before = app(StockService::class)->onHand($client->id, $asnLines[0]->id);
        $service = app(ReturnService::class);

        $receipt = $service->open(['original_order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'notes' => 'driver returned, consignee closed']);
        $this->assertMatchesRegularExpression('/^RET-\d{8}-0001$/', $receipt->receipt_no);
        $this->assertSame([4, 2], $receipt->lines->pluck('expected_qty')->all());
        $this->assertSame($asn->job_id, $receipt->job_id);

        [$l1, $l2] = $receipt->lines;
        $service->receiveLine($l1, 4, 'good');
        $service->receiveLine($l2, 2, 'damaged');
        $this->assertEquals($before, app(StockService::class)->onHand($client->id, $asnLines[0]->id), 'receiving alone must not change stock');
        $service->completeReceiving($receipt->fresh());
        $this->assertSame('received', $receipt->fresh()->status);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'return.received', 'job_id' => $asn->job_id]);

        $service->inspectLine($l1->fresh(), 'available', $supervisor->id);
        $service->inspectLine($l2->fresh(), 'damaged', $supervisor->id);
        $restocked = $l1->fresh()->stockUnit;
        $this->assertSame(['MEL-RCV-01-01', 'good', false, 4], [$restocked->location->full_code, $restocked->condition, $restocked->putaway_completed, $restocked->qty_on_hand]);
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $restocked->id, 'movement_type' => 'return', 'qty' => 4, 'source_type' => 'return_receipt', 'source_id' => $receipt->id]);
        $this->assertSame('MEL-QA-01-01', $l2->fresh()->stockUnit->location->full_code);
        $this->assertSame('damaged', $l2->fresh()->stockUnit->condition);
        $this->assertTrue($l2->fresh()->stockUnit->putaway_completed);
        $this->assertEquals($before, app(StockService::class)->onHand($client->id, $asnLines[0]->id), 'available only after putaway of the restocked unit');

        $service->completeInspection($receipt->fresh(), $supervisor->id);
        $this->assertSame('inspected', $receipt->fresh()->status);
        $inspected = OutboxEvent::query()->where('event_name', 'return.inspected')->firstOrFail();
        $this->assertSame(['available', 'damaged'], array_column($inspected->payload['lines'], 'disposition'));
        $this->assertSame($restocked->id, $inspected->payload['lines'][0]['stock_unit_id']);
        $this->assertDatabaseHas('warehouse_tasks', ['task_type' => 'return_inspection', 'order_id' => $order->id, 'status' => 'done', 'billable_qty' => 6]);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }
}
