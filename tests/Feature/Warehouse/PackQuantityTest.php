<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Services\OutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 OUTBOUND-01: the pack form was six fixed rows with no way to add one, and a second pack is refused — an order leaving as
 * more than six pieces could not be recorded (labels under-billed, the carrier quoted the wrong piece list). Now: three rows + 添加包裹
 * (a <template> row cloned by page script) and a 件数 column the server expands into that many Package rows, at most 200.
 */
class PackQuantityTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** A picked 15-carton fulfilment ready to pack. @return array{int, \App\Modules\Orders\Models\Order, \App\Models\User} */
    private function pickedFulfilment(): array
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => [$asnLine]] = $this->stockedAsn($client, $warehouse, [['mark' => 'CTN-15', 'cartons' => 15, 'weight_kg' => 150]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLine->id, 'qty' => 15]]);
        $outbound = app(OutboundService::class);
        ['tasks' => $tasks] = $outbound->releaseWave($warehouse->id, ['order_ids' => [$order->id]], $operator->id);
        foreach ($tasks->first()->lines as $line) {
            $outbound->confirmPick($line, $line->required_qty, $operator->id);
        }

        return [(int) DB::table('fulfilments')->where('order_id', $order->id)->value('id'), $order, $operator];
    }

    public function test_three_rows_with_quantities_become_fifteen_packages_labels_and_carrier_items(): void
    {
        [$fulfilment, $order, $operator] = $this->pickedFulfilment();

        $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fulfilment))->assertOk()
            ->assertSee(__('warehouse.outbound.qty'))->assertSee(__('warehouse.outbound.add_package'))
            ->assertSee('name="packages[2][qty]"', false)->assertDontSee('name="packages[3][qty]"', false) // three rows, not six
            ->assertSee('<template id="package-row-template">', false)->assertSee('packages[__INDEX__][weight_kg]', false);

        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [
            ['package_type' => 'carton', 'qty' => 10, 'weight_kg' => '12.5', 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400],
            ['package_type' => 'carton', 'qty' => 4, 'weight_kg' => '8', 'length_mm' => 500, 'width_mm' => 400, 'height_mm' => 300],
            ['package_type' => 'satchel', 'weight_kg' => '1.2', 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 50], // no qty = 1
        ]])->assertRedirect(route('warehouse.outbound.index'))->assertSessionHasNoErrors()->assertSessionHas('status', __('warehouse.outbound.packed', ['count' => 15]));

        $packages = Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilment)->orderBy('id')->get();
        $this->assertCount(15, $packages);
        $this->assertSame(array_map(fn ($n) => sprintf('PKG-%d-%02d', $fulfilment, $n), range(1, 15)), $packages->pluck('carton_label')->all());
        $this->assertSame(['carton' => 14, 'satchel' => 1], $packages->countBy('package_type')->all());
        $this->assertEqualsWithDelta(12.5, (float) $packages[9]->weight_kg, 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $packages[10]->weight_kg, 0.001);
        $this->assertSame(500, $packages[13]->length_mm);

        $packed = OutboxEvent::query()->where('event_name', 'outbound.packed')->where('job_id', $order->job_id)->firstOrFail();
        $this->assertCount(15, $packed->payload['packages']);
        $this->assertSame(15, $packed->payload['label_count']);
        $this->assertSame('PKG-'.$fulfilment.'-15', $packed->payload['packages'][14]['carton_label']);
        $this->assertDatabaseHas('warehouse_tasks', ['task_type' => 'pack', 'fulfilment_id' => $fulfilment, 'status' => 'done', 'billable_qty' => 15, 'billable_uom' => 'label']);
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertSee('PKG-'.$fulfilment.'-15');
    }

    public function test_quantities_are_validated_and_capped_at_two_hundred_packages(): void
    {
        [$fulfilment, $order, $operator] = $this->pickedFulfilment();
        $row = fn (int $qty) => ['package_type' => 'carton', 'qty' => $qty, 'weight_kg' => '5', 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 300];

        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [$row(0)]])->assertSessionHasErrors('packages.0.qty');
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [$row(201)]])->assertSessionHasErrors('packages.0.qty');
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [$row(150), $row(60)]])
            ->assertSessionHasErrors(['packages' => __('warehouse.outbound.errors.too_many_packages', ['max' => 200])]);
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [['package_type' => '', 'qty' => 3, 'weight_kg' => '']]])
            ->assertSessionHasErrors(['packages' => __('warehouse.outbound.errors.need_package')]);
        $this->assertSame(0, Package::query()->withoutGlobalScopes()->count());

        // Exactly 200 is fine; the service expands the same way for any caller.
        $this->assertCount(200, OutboundService::expandPackages([$row(150), $row(50)]));
        $this->assertSame([['package_type' => 'carton', 'weight_kg' => 5.0], ['package_type' => 'carton', 'weight_kg' => 5.0]], OutboundService::expandPackages([['package_type' => 'carton', 'weight_kg' => 5.0, 'qty' => 2]]));
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [$row(150), $row(50)]])->assertRedirect(route('warehouse.outbound.index'))->assertSessionHasNoErrors();
        $this->assertSame(200, Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilment)->count());
        $this->assertSame('PKG-'.$fulfilment.'-200', Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilment)->orderByDesc('id')->value('carton_label'));
    }
}
