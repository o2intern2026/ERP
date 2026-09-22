<?php

namespace Tests\Feature\Warehouse;

use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\PhysicalContainer;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PhysicalContainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-16 (CR #141): a physical container could not be edited or deleted after 保存 although 我方拖车 / 侧卸车 drive
 * charges, and 登记到港 fired them on one unconfirmed click. Now: header editable before arrival, deletion of an empty box, and a
 * confirmation page that lists the cartage / sideloader lines per member share before the event is published.
 */
class PhysicalContainerEditTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const BOX = 'TGHU9876543';

    private function memberAsn(Client $client, Warehouse $warehouse, int $expected): Asn
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => self::BOX, 'size' => '40', 'unpack_mode' => 'loose']]]);
        app(AsnService::class)->addLines($asn, [['container_no' => self::BOX, 'consignment_mark' => 'MK-'.$client->code, 'description' => 'Goods', 'expected_cartons' => $expected]]);

        return $asn;
    }

    public function test_header_is_editable_before_arrival_and_an_empty_box_can_be_deleted(): void
    {
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        $other = $this->warehouse('SYD');
        $client = $this->client();
        $asn = $this->memberAsn($client, $warehouse, 100);
        $box = app(PhysicalContainerService::class)->create(['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose', 'created_by' => $cs->id]);
        $this->assertFalse($box->cartage_by_us);

        // Operators never reach these pages; the show page offers 修改 / 删除 to the coordinator.
        $this->actingAs($this->staff('warehouse_operator'))->get(route('warehouse.physical_containers.edit', $box))->assertForbidden();
        $this->actingAs($cs)->get(route('warehouse.physical_containers.show', $box))->assertOk()
            ->assertSee(route('warehouse.physical_containers.edit', $box))->assertSee(__('warehouse.physical_containers.delete'));

        // The wrong flags are corrected: cartage on, size 20, ETA set — logged.
        $this->actingAs($cs)->get(route('warehouse.physical_containers.edit', $box))->assertOk()->assertSee('name="cartage_by_us"', false);
        $update = ['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '20', 'unpack_mode' => 'pallet', 'gross_weight_kg' => 19000, 'allocation_basis' => 'pallets', 'cartage_by_us' => 1, 'sideloader_required' => 1, 'eta_date' => today()->addDays(2)->toDateString(), 'notes' => 'fixed flags'];
        $this->actingAs($cs)->put(route('warehouse.physical_containers.update', $box), $update)->assertSessionHasNoErrors()->assertRedirect(route('warehouse.physical_containers.show', $box));
        $box->refresh();
        $this->assertSame(['20', 'pallet', true, true, 'pallets', 'fixed flags', today()->addDays(2)->toDateString()], [$box->size, $box->unpack_mode, $box->cartage_by_us, $box->sideloader_required, $box->allocation_basis, $box->notes, $box->eta_date->toDateString()]);
        $this->assertTrue(DB::table('activity_log')->where('subject_type', PhysicalContainer::class)->where('subject_id', $box->id)->where('properties->attributes->cartage_by_us', true)->exists());

        // Duplicates are refused as on create; the warehouse cannot change once a member is linked.
        $second = app(PhysicalContainerService::class)->create(['container_no' => 'OTHR1111111', 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose']);
        $this->actingAs($cs)->put(route('warehouse.physical_containers.update', $second), array_replace($update, ['container_no' => self::BOX]))->assertSessionHasErrors('edit');
        app(PhysicalContainerService::class)->link($box, [$asn->containers()->sole()->id]);
        $this->actingAs($cs)->put(route('warehouse.physical_containers.update', $box), array_replace($update, ['warehouse_id' => $other->id]))
            ->assertSessionHasErrors(['edit' => __('warehouse.physical_containers.errors.warehouse_change_with_members', ['no' => self::BOX])]);
        $this->assertSame($warehouse->id, $box->fresh()->warehouse_id);

        // Deletion: a box with a member is refused; the empty second box goes.
        $this->actingAs($cs)->delete(route('warehouse.physical_containers.destroy', $box))->assertSessionHasErrors(['edit' => __('warehouse.physical_containers.errors.cannot_delete', ['no' => self::BOX])]);
        $this->assertNotNull($box->fresh());
        $this->actingAs($cs)->delete(route('warehouse.physical_containers.destroy', $second))->assertRedirect(route('warehouse.physical_containers.index'))->assertSessionHasNoErrors();
        $this->assertNull($second->fresh());

        // After 登记到港 the header is locked (edit page redirects with the reason, update refused).
        app(PhysicalContainerService::class)->markArrived($box, $cs->id);
        $this->actingAs($cs)->get(route('warehouse.physical_containers.edit', $box))->assertRedirect(route('warehouse.physical_containers.show', $box))->assertSessionHasErrors('edit');
        $this->actingAs($cs)->put(route('warehouse.physical_containers.update', $box), array_replace($update, ['cartage_by_us' => 0]))
            ->assertSessionHasErrors(['edit' => __('warehouse.physical_containers.errors.locked_after_arrival', ['no' => self::BOX])]);
        $this->assertTrue($box->fresh()->cartage_by_us);
        $this->actingAs($cs)->get(route('warehouse.physical_containers.show', $box))->assertOk()->assertDontSee(route('warehouse.physical_containers.edit', $box));
    }

    public function test_arrival_goes_through_a_confirmation_that_lists_cartage_and_sideloader_per_member_share(): void
    {
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        $a = $this->client(['name' => 'Alpha Imports']);
        $b = $this->client(['name' => 'Bravo Trading']);
        $asnA = $this->memberAsn($a, $warehouse, 300);
        $asnB = $this->memberAsn($b, $warehouse, 100);
        $service = app(PhysicalContainerService::class);
        $box = $service->create(['container_no' => self::BOX, 'warehouse_id' => $warehouse->id, 'size' => '40', 'unpack_mode' => 'loose', 'gross_weight_kg' => 18000, 'cartage_by_us' => true, 'sideloader_required' => true]);
        $service->link($box, [$asnA->containers()->sole()->id, $asnB->containers()->sole()->id]);

        // The show page links to the confirmation instead of posting straight away.
        $show = $this->actingAs($cs)->get(route('warehouse.physical_containers.show', $box))->assertOk();
        $show->assertSee(route('warehouse.physical_containers.arrive_confirm', $box))->assertDontSee('action="'.route('warehouse.physical_containers.arrive', $box).'"', false);

        // The confirmation lists TR-CARTAGE-40 + TR-SIDELOADER per member at its share (75 % / 25 % on pre-advised cartons), no event yet.
        $page = $this->actingAs($cs)->get(route('warehouse.physical_containers.arrive_confirm', $box))->assertOk();
        $page->assertSee(__('warehouse.physical_containers.arrive_confirm.will_raise', ['codes' => 'TR-CARTAGE-40 + TR-SIDELOADER']))
            ->assertSee('Alpha Imports')->assertSee('Bravo Trading')->assertSee('75.00 %')->assertSee('25.00 %')
            ->assertSee(__('warehouse.physical_containers.arrive_confirm.confirm'))->assertSee('action="'.route('warehouse.physical_containers.arrive', $box).'"', false);
        $this->assertSame(2, substr_count($page->getContent(), '<code>TR-CARTAGE-40</code>'));
        $this->assertSame(2, substr_count($page->getContent(), '<code>TR-SIDELOADER</code>'));
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'physical_container.arrived')->count());
        $this->assertNull($box->fresh()->arrived_at);

        // Confirm → the event goes out once; a second confirmation page redirects with the reason.
        $this->actingAs($cs)->post(route('warehouse.physical_containers.arrive', $box))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'physical_container.arrived')->count());
        $this->actingAs($cs)->get(route('warehouse.physical_containers.arrive_confirm', $box))->assertRedirect(route('warehouse.physical_containers.show', $box))->assertSessionHasErrors('arrive');

        // A box with neither flag says the arrival raises nothing and points at 修改物理柜.
        $plain = $service->create(['container_no' => 'PLAIN0000001', 'warehouse_id' => $warehouse->id, 'size' => '20', 'unpack_mode' => 'loose']);
        $asnC = app(AsnService::class)->create(['client_id' => $a->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'PLAIN0000001', 'size' => '20', 'unpack_mode' => 'loose']]]);
        $service->link($plain, [$asnC->containers()->sole()->id]);
        $this->actingAs($cs)->get(route('warehouse.physical_containers.arrive_confirm', $plain))->assertOk()
            ->assertSee(__('warehouse.physical_containers.arrive_confirm.no_charges'))->assertSee(route('warehouse.physical_containers.edit', $plain))->assertDontSee('<code>TR-CARTAGE', false)->assertDontSee('id="arrive-preview"', false);
    }
}
