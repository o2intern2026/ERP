<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Orders\Services\AsnOrderService;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 编辑收件信息 (lead request 2026-09-11, CHANGE_REQUESTS #115). 从预报单生成派送订单 refuses a mark whose lines lack a full consignee
 * address (incomplete_delivery), and there was no screen to complete one: the manual add-line form had no address / suburb / state
 * and an existing line could not be edited at all — only an Excel import with address columns ever passed. Now every goods line not
 * yet on an order has an edit page (customer service and the warehouse roles), the blocked list links straight to it, one save can
 * cover the whole mark, and the add-line form carries the full address.
 */
class AsnLineDeliveryTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_a_blocked_mark_gets_its_address_completed_and_then_generates_its_order(): void
    {
        $this->app->singleton(OrderService::class, AsnOrderService::class); // the real OMS grouping rule, not the Fake
        $cs = $this->staff('customer_service');
        $this->actingAs($this->staff('warehouse_supervisor'));
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => [$a, $b, $other]] = $this->stockedAsn($client, $warehouse, [
            ['mark' => 'MK-1', 'description' => 'Kettles', 'cartons' => 4, 'deliver_to_address' => ''],
            ['mark' => 'MK-1', 'description' => 'Toasters', 'cartons' => 2, 'deliver_to_address' => ''],
            ['mark' => 'MK-2', 'description' => 'Mugs', 'cartons' => 1],
        ]);
        $this->assertSame('putaway', $asn->status);

        // 1. Generate: MK-2 becomes an order, MK-1 is blocked, and the blocked list links each line to the edit page.
        $this->actingAs($cs)->from(route('warehouse.asns.show', $asn))->followingRedirects()->post(route('warehouse.asns.generate_orders', $asn))->assertOk()
            ->assertSee(__('warehouse.asns.orders_generated', ['count' => 1, 'lines' => 1, 'blocked' => 1]))
            ->assertSee(__('warehouse.asns.blocked_reasons.incomplete_delivery'))
            ->assertSee(__('warehouse.asns.blocked_fix'))
            ->assertSee(route('warehouse.asns.lines.delivery.edit', [$asn, $a]))
            ->assertSee(route('warehouse.asns.lines.delivery.edit', [$asn, $b]))
            ->assertSee(__('warehouse.asns.edit_delivery'));
        $this->assertNotNull($other->fresh()->order_line_id);
        $this->assertNull($a->fresh()->order_line_id);

        // 2. The edit page is prefilled, offers to copy to the sibling under MK-1, and refuses an incomplete address in Chinese.
        $this->actingAs($cs)->get(route('warehouse.asns.lines.delivery.edit', [$asn, $a]))->assertOk()
            ->assertSee(__('warehouse.asns.edit_delivery'))->assertSee('Kettles')
            ->assertSee(__('warehouse.asns.delivery_apply_to_mark', ['count' => 1, 'mark' => 'MK-1']))
            ->assertSee('name="apply_to_mark"', false);
        $this->actingAs($cs)->from(route('warehouse.asns.lines.delivery.edit', [$asn, $a]))
            ->post(route('warehouse.asns.lines.delivery.update', [$asn, $a]), ['deliver_to_name' => 'Shop A', 'deliver_to_address' => '', 'deliver_to_suburb' => 'Richmond', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3121'])
            ->assertRedirect(route('warehouse.asns.lines.delivery.edit', [$asn, $a]))
            ->assertSessionHasErrors(['deliver_to_address']);
        $this->assertSame('', $a->fresh()->deliver_to_address);

        // 3. A complete address is saved and copied to the sibling line under the same mark; the other mark is untouched.
        $address = ['deliver_to_name' => 'Shop A', 'deliver_to_phone' => '0400 000 000', 'deliver_to_address' => '1 High St', 'deliver_to_suburb' => 'Richmond', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3121', 'fba_reference' => 'FBA-1'];
        $this->actingAs($cs)->post(route('warehouse.asns.lines.delivery.update', [$asn, $a]), $address + ['consignment_mark' => 'MK-1', 'apply_to_mark' => '1'])
            ->assertRedirect(route('warehouse.asns.show', $asn))
            ->assertSessionHas('status', __('warehouse.asns.delivery_saved', ['count' => 2]));
        foreach ([$a, $b] as $line) {
            $this->assertSame($address, $line->fresh()->only(array_keys($address)), "line {$line->id} shares the address of its mark");
            $this->assertTrue($line->fresh()->hasCompleteDelivery());
        }
        $this->assertSame('1 Test St', $other->fresh()->deliver_to_address);

        // 4. Generate again: MK-1 becomes an order with both lines, nothing is blocked, and linked lines can no longer be edited.
        $this->actingAs($cs)->from(route('warehouse.asns.show', $asn))->followingRedirects()->post(route('warehouse.asns.generate_orders', $asn))->assertOk()
            ->assertSee(__('warehouse.asns.orders_generated', ['count' => 1, 'lines' => 2, 'blocked' => 0]))
            ->assertDontSee(__('warehouse.asns.delivery_incomplete'))
            ->assertDontSee(__('warehouse.asns.edit_delivery'));
        $this->assertNotNull($a->fresh()->order_line_id);
        $this->actingAs($cs)->get(route('warehouse.asns.lines.delivery.edit', [$asn, $a]))->assertOk()
            ->assertSee(__('warehouse.asns.errors.delivery_locked', ['id' => $a->id]))->assertDontSee('name="deliver_to_address"', false);
        $this->actingAs($cs)->from(route('warehouse.asns.lines.delivery.edit', [$asn, $a]))
            ->post(route('warehouse.asns.lines.delivery.update', [$asn, $a]), $address + ['deliver_to_address' => '99 Changed St'])
            ->assertRedirect(route('warehouse.asns.lines.delivery.edit', [$asn, $a]))
            ->assertSessionHasErrors(['delivery' => __('warehouse.asns.errors.delivery_locked', ['id' => $a->id])]);
        $this->assertSame('1 High St', $a->fresh()->deliver_to_address);
    }

    public function test_the_edit_page_is_scoped_to_its_asn_and_roles_and_the_add_line_form_carries_the_full_address(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asnA = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel']);
        $asnB = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel']);
        [$line] = app(AsnService::class)->addLines($asnA, [['description' => 'Mugs', 'expected_cartons' => 1]]);

        $this->actingAs($this->staff('warehouse_operator'))->get(route('warehouse.asns.lines.delivery.edit', [$asnB, $line]))->assertNotFound();
        $this->actingAs($this->staff('finance'))->get(route('warehouse.asns.lines.delivery.edit', [$asnA, $line]))->assertForbidden();
        $this->actingAs($this->staff('warehouse_operator'))->get(route('warehouse.asns.lines.delivery.edit', [$asnA, $line]))->assertOk()->assertSee('Mugs');

        // The ASN page flags the incomplete line and the manual add-line form now asks for the whole address.
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asnA))->assertOk()
            ->assertSee(__('warehouse.asns.delivery_incomplete'))
            ->assertSee(route('warehouse.asns.lines.delivery.edit', [$asnA, $line]))
            ->assertSee('name="deliver_to_phone"', false)->assertSee('name="deliver_to_address"', false)
            ->assertSee('name="deliver_to_suburb"', false)->assertSee('name="deliver_to_state"', false)
            ->assertDontSee('placeholder="收件人"', false);
        $this->actingAs($cs)->post(route('warehouse.asns.lines.store', $asnA), [
            'description' => 'Plates', 'expected_cartons' => 3, 'consignment_mark' => 'MK-9', 'deliver_to_name' => 'Shop C', 'deliver_to_phone' => '0411 111 111',
            'deliver_to_address' => '9 Lane', 'deliver_to_suburb' => 'Fitzroy', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3065', 'fba_reference' => 'FBA-9',
        ])->assertRedirect(route('warehouse.asns.show', $asnA));
        $plates = AsnLine::query()->where('consignment_mark', 'MK-9')->sole();
        $this->assertTrue($plates->hasCompleteDelivery());
        $this->assertSame(['Shop C', '0411 111 111', 'Fitzroy', 'VIC', 'FBA-9'], [$plates->deliver_to_name, $plates->deliver_to_phone, $plates->deliver_to_suburb, $plates->deliver_to_state, $plates->fba_reference]);
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asnA))->assertOk()->assertSee('Shop C · Fitzroy VIC 3065');
    }
}
