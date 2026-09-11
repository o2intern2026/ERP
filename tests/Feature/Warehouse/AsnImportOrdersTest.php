<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\Job;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Support\Contracts\InboundService;
use App\Support\Contracts\JobService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 从订单导入货物行 (lead request 2026-09-11, CHANGE_REQUESTS #119): on a booked / arrived / receiving ASN the staff tick the client's
 * pending orders and every goods line — consignee, phone, address, postcode, FBA reference, mark, description, cartons — comes
 * from the order (InboundService::addOrderLinesToAsn ← OrderService::attachOrdersToAsn). The manual add-line form is demoted to
 * the exception (collapsed), each imported line names its source order, and the orders are merged into the ASN's Job.
 */
class AsnImportOrdersTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** A portal-style order: no Job given → its own loose Job; goods lines not linked to any ASN. */
    private function order(int $clientId, string $mark, array $lines): Order
    {
        return app(OrderCreationService::class)->create([
            'client_id' => $clientId, 'order_type' => 'from_stock', 'consignment_mark' => $mark, 'fba_reference' => 'FBA-'.$mark,
            'deliver_to_name' => 'Shop '.$mark, 'deliver_to_phone' => '0400 000 '.str_pad((string) (crc32($mark) % 1000), 3, '0', STR_PAD_LEFT), 'deliver_to_address' => '1 High St', 'deliver_to_suburb' => 'Richmond', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3121',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(14)->toDateString(), 'service_level' => 'standard',
            'lines' => array_map(fn (array $l) => ['description_cn' => $l['cn'] ?? null, 'description_en' => $l['en'], 'package_type' => 'carton', 'carton_qty' => $l['qty'], 'actual_weight_kg' => $l['kg'] ?? null], $lines),
        ], null, 'portal');
    }

    /** A coordinator's container ASN for the client, one 40' loose container by default, no goods lines yet. */
    private function containerAsn(int $clientId, int $warehouseId, array $containerNos = ['MSKU1234567']): Asn
    {
        return app(AsnService::class)->create([
            'client_id' => $clientId, 'warehouse_id' => $warehouseId, 'inbound_type' => 'container',
            'containers' => array_map(fn (string $no) => ['container_no' => $no, 'size' => '40', 'unpack_mode' => 'loose'], $containerNos),
        ]);
    }

    /** The rendered `order_ids[]` checkboxes of the import card, keyed by order id → the full <input …> tag. */
    private function candidateBoxes(string $html): array
    {
        preg_match_all('/<input type="checkbox" name="order_ids\[\]" value="(\d+)"[^>]*>/', $html, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn (array $match) => [(int) $match[1] => $match[0]])->all();
    }

    public function test_staff_import_the_clients_pending_orders_as_goods_lines_on_the_asn_page_and_putaway_allocates_them(): void
    {
        $cs = $this->staff('customer_service');
        $supervisor = $this->staff('warehouse_supervisor');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $this->actingAs($supervisor);
        $asn = $this->containerAsn($client->id, $warehouse->id);
        $containerId = (int) $asn->containers()->value('id');

        $a = $this->order($client->id, 'MK-A', [['cn' => '蓝牙音箱', 'en' => 'Bluetooth speaker', 'qty' => 10, 'kg' => 8.5], ['en' => 'Kettle', 'qty' => 4]]);
        $b = $this->order($client->id, 'MK-B', [['en' => 'Mugs', 'qty' => 6]]);
        $foreign = $this->order($this->client()->id, 'MK-Z', [['en' => 'Plates', 'qty' => 1]]);
        $this->assertNotSame($a->job_id, $b->job_id, 'portal orders open their own loose Jobs');
        $this->assertNotSame($asn->job_id, $a->job_id, 'the hand-made ASN has its own Job');
        app(OrderStatusService::class)->transitionOperational($a, 'confirmed', $cs->id);
        app(OutboxDispatcher::class)->dispatchDue(); // order.confirmed with no ASN line behind it → nothing reserved
        $this->assertSame('confirmed', $a->fresh()->operational_status);
        $this->assertSame('received', $b->fresh()->operational_status);

        // The ASN page offers the client's pending orders — unticked (staff pick the ones arriving together), with a 全选 box and a
        // header button jumping to the card; the manual form is collapsed behind its summary and the empty state points at the import.
        $page = $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()
            ->assertSee(__('warehouse.asns.import_orders_title'))
            ->assertSee(__('warehouse.asns.import_orders_hint'))
            ->assertSee(__('warehouse.asns.import_orders_button'))
            ->assertSee(__('warehouse.asns.import_orders_select_all'))
            ->assertSee('href="#import-orders"', false)
            ->assertSee(__('warehouse.asns.no_lines_hint'))
            ->assertSee($a->order_no)->assertSee($b->order_no)->assertDontSee($foreign->order_no)
            ->assertSee(__('warehouse.asns.manual_add_summary'))
            ->assertSee('<details id="manual-line">', false)
            ->assertDontSee(__('warehouse.asns.from_orders_badge'))
            ->assertDontSee(__('warehouse.asns.import_orders_none'));
        $boxes = $this->candidateBoxes($page->getContent());
        $this->assertSame([$a->id, $b->id], array_keys($boxes));
        foreach ($boxes as $box) {
            $this->assertStringNotContainsString('checked', $box, 'nothing is pre-ticked');
            $this->assertStringNotContainsString('disabled', $box);
        }

        // Import both: 3 goods lines from the orders, both orders merged into the ASN's Job, their emptied Jobs cancelled.
        $aOldJobId = $a->job_id;
        $bOldJobId = $b->job_id;
        $aOldJobNo = $a->job->job_no;
        $bOldJobNo = $b->job->job_no;
        $response = $this->actingAs($cs)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id, $b->id]]);
        $response->assertRedirect(route('warehouse.asns.show', $asn))->assertSessionHasNoErrors()->assertSessionHas('status');
        $status = (string) session('status');
        $this->assertStringContainsString(__('warehouse.asns.orders_imported', ['count' => 2, 'lines' => 3]), $status);
        foreach ([$asn->job->job_no, $a->order_no, $b->order_no, $aOldJobNo, $bOldJobNo] as $named) {
            $this->assertStringContainsString($named, $status, 'the merged note names the shipment Job, the merged orders and the closed Jobs');
        }

        $asn->refresh();
        $a->refresh();
        $b->refresh();
        $this->assertSame([$asn->job_id, $asn->job_id, 'booked', 'container'], [$a->job_id, $b->job_id, $asn->status, $asn->inbound_type]);
        $this->assertSame('cancelled', Job::query()->findOrFail($aOldJobId)->operational_status);
        $this->assertSame('cancelled', Job::query()->findOrFail($bOldJobId)->operational_status);
        $this->assertSame('open', Job::query()->findOrFail($asn->job_id)->operational_status);
        $this->assertSame(3, (int) $asn->containers()->value('line_count'));

        $lines = $asn->lines()->orderBy('id')->get();
        $this->assertCount(3, $lines);
        $first = $lines->firstWhere('order_line_id', $a->lines()->orderBy('id')->value('id'));
        $this->assertNotNull($first);
        $this->assertSame(
            ['MK-A', '蓝牙音箱 / Bluetooth speaker', 10, 'Shop MK-A', $a->deliver_to_phone, '1 High St', 'Richmond', 'VIC', '3121', 'FBA-MK-A', '8.500', $containerId],
            [$first->consignment_mark, $first->description, $first->expected_cartons, $first->deliver_to_name, $first->deliver_to_phone, $first->deliver_to_address, $first->deliver_to_suburb, $first->deliver_to_state, $first->deliver_to_postcode, $first->fba_reference, $first->weight_kg, $first->container_id],
        );
        foreach ([$a, $b] as $order) {
            foreach ($order->lines()->get() as $line) {
                $asnLine = $lines->firstWhere('order_line_id', $line->id);
                $this->assertNotNull($asnLine, "order line {$line->id} has its ASN line");
                $this->assertSame($asnLine->id, $line->asn_line_id, 'linked both ways');
                $this->assertSame($asn->id, $asnLine->asn_id);
                $this->assertSame($containerId, $asnLine->container_id, 'the only container of the ASN carries the imported lines');
                $this->assertSame($line->carton_qty, $asnLine->expected_cartons);
                $this->assertSame(
                    [$order->consignment_mark, $order->deliver_to_name, $order->deliver_to_phone, $order->deliver_to_address, $order->deliver_to_suburb, $order->deliver_to_state, $order->deliver_to_postcode, $order->fba_reference],
                    [$asnLine->consignment_mark, $asnLine->deliver_to_name, $asnLine->deliver_to_phone, $asnLine->deliver_to_address, $asnLine->deliver_to_suburb, $asnLine->deliver_to_state, $asnLine->deliver_to_postcode, $asnLine->fba_reference],
                );
                $this->assertSame($order->operational_status === 'confirmed' ? $line->carton_qty : 0, $line->qty_backordered, 'confirmed orders wait as backorder, received ones do not');
            }
        }
        $this->assertSame(0, AsnLine::query()->where('asn_id', $asn->id)->whereNull('order_line_id')->count());

        // The page now shows where each line came from, has no candidates left, and the manual form is still the collapsed exception.
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()
            ->assertSee(__('warehouse.asns.from_orders_badge'))
            ->assertSee(__('warehouse.asns.order_col'))
            ->assertSee($a->order_no)->assertSee($b->order_no)
            ->assertSee(route('orders.show', $a), false)->assertSee(route('orders.show', $b), false)
            ->assertSee(__('warehouse.asns.import_orders_none'))
            ->assertSee(__('warehouse.asns.manual_add_summary'))
            ->assertSee('MSKU1234567');
        $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk() // 待建预报 no longer lists them
            ->assertDontSee(route('orders.show', $a).'"', false)->assertDontSee(route('orders.show', $b).'"', false)->assertSee(route('orders.show', $foreign).'"', false);

        // Goods arrive: receive + put away every line → asn.putaway_completed → the confirmed order is allocated; the received one waits.
        $operator = $this->staff('warehouse_operator');
        $this->actingAs($operator);
        foreach ($lines as $line) {
            $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $line->expected_cartons, 'units' => [['unit_type' => 'carton', 'carton_qty' => $line->expected_cartons]]], $this->location($warehouse, 'receiving'), $operator->id);
            foreach ($units as $unit) {
                app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));
            }
        }
        $this->assertSame('putaway', $asn->fresh()->status);
        app(OutboxDispatcher::class)->dispatchDue(); // asn.putaway_completed → Orders allocates the backorders → stock.reserved
        app(OutboxDispatcher::class)->dispatchDue();
        $a->refresh();
        $this->assertSame('allocated', $a->operational_status);
        $this->assertSame(0, (int) $a->lines()->sum('qty_backordered'));
        $this->assertSame('received', $b->fresh()->operational_status);

        // A put-away ASN offers no import block any more.
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertDontSee(__('warehouse.asns.import_orders_title'));
    }

    public function test_import_guards_other_clients_jobs_with_an_asn_closed_asns_and_roles(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $this->actingAs($this->staff('warehouse_supervisor'));
        $asn = $this->containerAsn($client->id, $warehouse->id);
        $a = $this->order($client->id, 'MK-A', [['en' => 'Kettle', 'qty' => 4]]);
        $b = $this->order($client->id, 'MK-B', [['en' => 'Mugs', 'qty' => 6]]);
        $foreign = $this->order($this->client()->id, 'MK-Z', [['en' => 'Plates', 'qty' => 1]]);
        $show = route('warehouse.asns.show', $asn);

        // Another client's order is refused in Chinese; nothing is linked or moved.
        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id, $foreign->id]])
            ->assertRedirect($show)->assertSessionHasErrors(['import_orders' => __('orders.inbound.errors.asn_other_client', ['order_no' => $foreign->order_no])]);
        $this->assertSame(0, $asn->lines()->count());
        $this->assertNull($a->lines()->first()->asn_line_id);
        $this->assertNull($foreign->lines()->first()->asn_line_id);
        $this->assertSame($a->job_id, $a->fresh()->job_id, 'nothing moved');

        // B's Job already carries an ASN → B cannot be merged into this ASN's Job; its row is disabled with the hint before submit.
        app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel', 'job_id' => $b->job_id]);
        $page = $this->actingAs($cs)->get($show)->assertOk()->assertSee(__('warehouse.asns.import_orders_job_has_asn'));
        $boxes = $this->candidateBoxes($page->getContent());
        $this->assertStringContainsString('disabled', $boxes[$b->id]);
        $this->assertStringNotContainsString('disabled', $boxes[$a->id]);
        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id, $b->id]])
            ->assertRedirect($show)->assertSessionHasErrors(['import_orders' => __('orders.inbound.errors.job_has_asn', ['order_no' => $b->order_no, 'job_no' => $b->job->job_no])]);
        $this->assertSame(0, $asn->lines()->count());
        $this->assertNull($a->lines()->first()->asn_line_id);
        $this->assertNull($b->lines()->first()->asn_line_id);
        $this->assertSame($b->job_id, $b->fresh()->job_id, 'nothing moved');

        // Validation: at least one order id (the Chinese 请至少勾选一张订单, not the generic 必填), integers only; a failed submit keeps the ticks.
        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => []])->assertRedirect($show)
            ->assertSessionHasErrors(['order_ids' => __('orders.inbound.errors.none_selected')]);
        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => ['x']])->assertRedirect($show)->assertSessionHasErrors(['order_ids.0']);
        $page = $this->actingAs($cs)->followingRedirects()->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id, $b->id]])->assertOk()
            ->assertSee(__('orders.inbound.errors.job_has_asn', ['order_no' => $b->order_no, 'job_no' => $b->job->job_no]));
        $this->assertStringContainsString('checked', $this->candidateBoxes($page->getContent())[$a->id], 'the selection survives the refusal');

        // Roles: operators and dispatchers may not import (the operator may still open the page).
        $this->actingAs($this->staff('warehouse_operator'))->get($show)->assertOk();
        $this->actingAs($this->staff('warehouse_operator'))->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id]])->assertForbidden();
        $this->actingAs($this->staff('dispatcher'))->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id]])->assertForbidden();
        $this->assertNull($a->lines()->first()->asn_line_id);

        // A alone works for the supervisor — then the ASN is put away and cannot take orders any more.
        $this->actingAs($this->staff('warehouse_supervisor'))->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id]])->assertRedirect($show)->assertSessionHasNoErrors();
        $this->assertSame(1, $asn->lines()->count());
        $this->assertSame($asn->lines()->value('id'), $a->lines()->first()->fresh()->asn_line_id);
        $this->assertSame($asn->job_id, $a->fresh()->job_id);

        $asn->update(['status' => 'putaway']);
        $c = $this->order($client->id, 'MK-C', [['en' => 'Bowls', 'qty' => 2]]);
        $this->actingAs($cs)->get($show)->assertOk()->assertDontSee(__('warehouse.asns.import_orders_title'))->assertDontSee('name="order_ids[]"', false);
        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$c->id]])
            ->assertRedirect($show)->assertSessionHasErrors(['import_orders' => __('warehouse.asns.errors.import_orders_closed', ['no' => $asn->asn_no])]);
        $this->assertSame(1, $asn->lines()->count());
        $this->assertNull($c->lines()->first()->asn_line_id);
        $this->assertNotSame($asn->job_id, $c->fresh()->job_id, 'nothing moved');
    }

    public function test_lines_float_on_an_asn_without_containers_and_need_a_chosen_container_when_there_are_several(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $this->actingAs($this->staff('warehouse_supervisor'));

        // No container (loose truck): the lines have no container and nothing asks for one.
        $loose = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $a = $this->order($client->id, 'MK-A', [['en' => 'Kettle', 'qty' => 4]]);
        $this->actingAs($cs)->get(route('warehouse.asns.show', $loose))->assertOk()->assertDontSee('name="container_no"', false);
        $this->actingAs($cs)->from(route('warehouse.asns.show', $loose))->post(route('warehouse.asns.import_orders', $loose), ['order_ids' => [$a->id]])->assertSessionHasNoErrors();
        $this->assertSame([1, null], [$loose->lines()->count(), $loose->lines()->value('container_id')]);

        // Two containers: the form offers the choice and insists on it; the lines land on the chosen one and its line_count follows.
        $twin = $this->containerAsn($client->id, $warehouse->id, ['MSKU1111111', 'MSKU2222222']);
        $show = route('warehouse.asns.show', $twin);
        $b = $this->order($client->id, 'MK-B', [['en' => 'Mugs', 'qty' => 6], ['en' => 'Plates', 'qty' => 2]]);
        $pick = __('warehouse.asns.errors.import_orders_pick_container', ['no' => $twin->asn_no, 'containers' => 'MSKU1111111, MSKU2222222']);
        $this->actingAs($cs)->get($show)->assertOk()->assertSee('name="container_no"', false)->assertSee(__('warehouse.asns.import_orders_container'));
        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $twin), ['order_ids' => [$b->id]])
            ->assertRedirect($show)->assertSessionHasErrors(['container_no' => $pick]);
        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $twin), ['order_ids' => [$b->id], 'container_no' => 'MSKU9999999'])
            ->assertRedirect($show)->assertSessionHasErrors(['container_no' => $pick]);
        $this->assertSame(0, $twin->lines()->count());
        $this->assertNull($b->lines()->first()->asn_line_id);
        $this->assertNotSame($twin->job_id, $b->fresh()->job_id, 'nothing moved');

        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $twin), ['order_ids' => [$b->id], 'container_no' => 'MSKU2222222'])
            ->assertRedirect($show)->assertSessionHasNoErrors();
        $second = $twin->containers()->where('container_no', 'MSKU2222222')->first();
        $this->assertSame(2, $twin->lines()->count());
        $this->assertSame([$second->id, $second->id], $twin->lines()->pluck('container_id')->all());
        $this->assertSame([0, 2], $twin->containers()->orderBy('id')->pluck('line_count')->map(fn ($n) => (int) $n)->all());

        // Warehouse enforces the same rule for any caller of the contract: a floating line on a multi-container ASN is refused.
        $c = $this->order($client->id, 'MK-C', [['en' => 'Bowls', 'qty' => 1]]);
        try {
            app(InboundService::class)->addOrderLinesToAsn($twin->id, [['order_line_id' => $c->lines()->value('id'), 'description' => 'Bowls', 'expected_cartons' => 1]]);
            $this->fail('a line without a container on a two-container ASN must be refused');
        } catch (RuleViolation $e) {
            $this->assertSame($pick, $e->userMessage());
        }
        $this->assertSame(2, $twin->lines()->count());
    }

    public function test_an_order_whose_job_still_has_undelivered_events_waits_and_an_emptied_job_is_not_cancelled_under_pending_events(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $this->actingAs($this->staff('warehouse_supervisor'));
        $asn = $this->containerAsn($client->id, $warehouse->id);
        $show = route('warehouse.asns.show', $asn);

        // Dispatch-now switched off: order.confirmed stays pending under A's own Job, exactly the cron-only case.
        config(['erp.outbox_dispatch_now' => []]);
        $a = $this->order($client->id, 'MK-A', [['en' => 'Kettle', 'qty' => 4]]);
        app(OrderStatusService::class)->transitionOperational($a, 'confirmed', $cs->id);
        $this->assertSame(1, OutboxEvent::query()->where('job_id', $a->job_id)->where('status', 'pending')->count());
        $aJobId = $a->job_id;

        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id]])
            ->assertRedirect($show)->assertSessionHasErrors(['import_orders' => __('orders.inbound.errors.events_pending', ['order_no' => $a->order_no])]);
        $this->assertSame(0, $asn->lines()->count());
        $this->assertNull($a->lines()->first()->asn_line_id);
        $this->assertSame($aJobId, $a->fresh()->job_id, 'nothing moved');
        $this->assertSame('open', Job::query()->findOrFail($aJobId)->operational_status);

        // Cron delivers (hop by hop: order.confirmed → the follow-ups its consumers publish) → the shipment Transport opens sits
        // under A's Job → the import moves it along and the emptied Job closes.
        for ($pass = 0; $pass < 5 && OutboxEvent::query()->where('job_id', $aJobId)->whereIn('status', ['pending', 'failed'])->exists(); $pass++) {
            app(OutboxDispatcher::class)->dispatchDue();
        }
        $this->assertSame(0, OutboxEvent::query()->where('job_id', $aJobId)->whereIn('status', ['pending', 'failed'])->count());
        $this->actingAs($cs)->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$a->id]])->assertRedirect($show)->assertSessionHasNoErrors();
        $this->assertSame($asn->job_id, $a->fresh()->job_id);
        $this->assertSame('cancelled', Job::query()->findOrFail($aJobId)->operational_status);
        $this->assertSame(0, DB::table('shipments')->where('job_id', $aJobId)->count());
        $this->assertSame(1, DB::table('shipments')->where('order_id', $a->id)->where('job_id', $asn->job_id)->count(), 'the shipment followed the order');

        // JobService::cancelIfEmpty on its own: a Job nothing refers to but with an undelivered event stays open until it is delivered.
        $jobs = app(JobService::class);
        $empty = $jobs->create($client->id, 'loose');
        $event = OutboxEvent::query()->create([
            'event_id' => (string) Str::uuid(), 'event_name' => 'order.confirmed', 'event_version' => 1, 'correlation_id' => 'test',
            'job_id' => $empty['job_id'], 'client_id' => $client->id, 'payload' => [], 'status' => 'failed', 'attempts' => 1, 'available_at' => now(), 'created_at' => now(),
        ]);
        $this->assertFalse($jobs->cancelIfEmpty($empty['job_id'], 'note'));
        $this->assertSame('open', Job::query()->findOrFail($empty['job_id'])->operational_status);
        $event->update(['status' => 'published', 'published_at' => now()]);
        $this->assertTrue($jobs->cancelIfEmpty($empty['job_id'], 'note'));
        $this->assertSame('cancelled', Job::query()->findOrFail($empty['job_id'])->operational_status);
    }

    public function test_manual_form_reopens_on_its_own_errors_and_the_badge_follows_provenance_not_the_link(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $this->actingAs($supervisor);
        $asn = $this->containerAsn($client->id, $warehouse->id);
        $show = route('warehouse.asns.show', $asn);

        // A manual line that fails validation comes back with the collapsed form open and the error next to it — not lost behind the summary.
        $this->actingAs($supervisor)->followingRedirects()->from($show)->post(route('warehouse.asns.lines.store', $asn), ['consignment_mark' => 'MK-M', 'expected_cartons' => 3])
            ->assertOk()->assertSee('<details id="manual-line" open>', false)->assertSee('value="MK-M"', false);
        $this->actingAs($supervisor)->get($show)->assertOk()->assertSee('<details id="manual-line">', false);

        // ASN first (goods announced by hand), order generated FROM it later: the line is linked but the ASN was not 由订单生成.
        $this->travel(-10)->minutes();
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Hand-made goods', 'expected_cartons' => 5, 'consignment_mark' => 'MK-M']]);
        $this->travelBack();
        $order = $this->order($client->id, 'MK-M', [['en' => 'Hand-made goods', 'qty' => 5]]);
        $orderLine = $order->lines()->first();
        AsnLine::query()->whereKey($line->id)->update(['order_line_id' => $orderLine->id]);
        OrderLine::query()->whereKey($orderLine->id)->update(['asn_line_id' => $line->id]);
        $this->actingAs($supervisor)->get($show)->assertOk()
            ->assertSee(__('warehouse.asns.order_col'))->assertSee(route('orders.show', $order), false)
            ->assertDontSee(__('warehouse.asns.from_orders_badge'));

        // Orders first: an imported line earns the badge.
        $b = $this->order($client->id, 'MK-B', [['en' => 'Mugs', 'qty' => 6]]);
        $this->actingAs($supervisor)->from($show)->post(route('warehouse.asns.import_orders', $asn), ['order_ids' => [$b->id]])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->get($show)->assertOk()->assertSee(__('warehouse.asns.from_orders_badge'));
    }
}
