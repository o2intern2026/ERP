<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\OutboundDispatch;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\Wave;
use App\Modules\Warehouse\Services\OutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CHANGE_REQUESTS #156 批量发运交接: ticked packed batches are handed over with the row defaults; unbooked ones follow the form's choice; refused ones are named. */
class DispatchBulkTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_ticked_packed_batches_are_dispatched_in_one_post_and_unbooked_ones_follow_the_choice(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'DB1', 'cartons' => 8, 'weight_kg' => 40], ['mark' => 'DB2', 'cartons' => 6, 'weight_kg' => 30]]);
        $orderA = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]]);
        $orderB = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[1]->id, 'qty' => 2]]);
        $fulfilmentA = (int) DB::table('fulfilments')->where('order_id', $orderA->id)->value('id');
        $fulfilmentB = (int) DB::table('fulfilments')->where('order_id', $orderB->id)->value('id');
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$orderA->id, $orderB->id]])->assertRedirect();
        Wave::query()->firstOrFail();
        foreach (WarehouseTask::query()->where('task_type', 'pick')->whereIn('fulfilment_id', [$fulfilmentA, $fulfilmentB])->get() as $task) {
            foreach ($task->lines as $line) {
                app(OutboundService::class)->confirmPick($line, (int) $line->required_qty, $operator->id);
            }
        }
        foreach ([$fulfilmentA => 3, $fulfilmentB => 2] as $fulfilmentId => $qty) {
            app(OutboundService::class)->pack($fulfilmentId, [['package_type' => 'carton', 'qty' => $qty, 'weight_kg' => 5, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150]], $operator->id);
        }

        // The board: a checkbox per 待发运 batch bound to the bulk form; no raw lang key.
        $page = $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()
            ->assertSee('id="dispatch-bulk"', false)->assertSee('name="fulfilment_ids[]" value="'.$fulfilmentA.'" form="dispatch-bulk"', false)
            ->assertSee(__('warehouse.outbound.dispatch_bulk.title'))->assertSee('name="unbooked"', false);
        $this->assertDoesNotMatchRegularExpression('/warehouse\.outbound\.dispatch_bulk\./', $page->getContent());

        // Nothing ticked → refusal. Neither batch has a booked shipment (no Transport in this test): with the default choice both are skipped and named.
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch_bulk'), [])->assertSessionHasErrors('fulfilment_ids');
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch_bulk'), ['fulfilment_ids' => [$fulfilmentA, $fulfilmentB]])
            ->assertRedirect(route('warehouse.outbound.index'))->assertSessionHasErrors('dispatch')->assertSessionMissing('status');
        $this->assertStringContainsString(__('warehouse.outbound.dispatch_bulk.unbooked_skipped'), session('errors')->first('dispatch'));
        $this->assertSame(0, OutboundDispatch::query()->count());

        // 客户自提 for unbooked batches: both handed over in one post, packages counted; a second post finds them dispatched already.
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch_bulk'), ['fulfilment_ids' => [$fulfilmentA, $fulfilmentB], 'unbooked' => 'client'])
            ->assertRedirect(route('warehouse.outbound.index'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('warehouse.outbound.dispatch_bulk.done', ['count' => 2, 'packages' => 5]));
        $dispatches = OutboundDispatch::query()->orderBy('id')->get();
        $this->assertCount(2, $dispatches);
        $this->assertSame(['client', 'client'], $dispatches->pluck('handed_to')->all());
        $this->assertSame([3, 2], $dispatches->pluck('package_count')->map(fn ($v) => (int) $v)->all());
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'outbound.dispatched', 'job_id' => $asn->job_id]);
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch_bulk'), ['fulfilment_ids' => [$fulfilmentA], 'unbooked' => 'client'])->assertSessionHasErrors('dispatch');
        $this->actingAs($this->staff('customer_service'))->post(route('warehouse.outbound.dispatch_bulk'), ['fulfilment_ids' => [$fulfilmentA]])->assertForbidden();
    }
}
