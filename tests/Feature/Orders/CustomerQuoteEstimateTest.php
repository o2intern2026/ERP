<?php

namespace Tests\Feature\Orders;

use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * A7b / OMS-3 (ERP_PLAN §3.9 Q3 "定价 = 服务费预估"): the estimate prices the order's expected warehouse services through Billing's
 * RateService / QuoteService (Edward standard card), shows Transport's preliminary freight in customer prices, never shows cost,
 * and a re-estimate supersedes the previous customer quote.
 */
class CustomerQuoteEstimateTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_estimate_prices_a_mixed_pallet_and_carton_order_into_a_customer_quote(): void
    {
        $client = $this->client();
        $order = $this->order($client, ['lines' => [
            ['description_cn' => '展示架', 'package_type' => 'pallet', 'carton_qty' => 2],
            ['description_cn' => '灯具', 'package_type' => 'carton', 'carton_qty' => 10, 'actual_weight_kg' => 300], // 30 kg per carton → 22–45 band
            ['description_cn' => '配件', 'package_type' => 'carton', 'carton_qty' => 4, 'actual_weight_kg' => 8],    // 2 kg per carton → < 22 band
            ['description_cn' => '机器', 'package_type' => 'carton', 'carton_qty' => 2, 'actual_weight_kg' => 100],  // 50 kg per carton → ≥ 45 band
        ]]);
        $staff = $this->staff('customer_service');

        $this->actingAs($staff)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.estimate.none'))->assertSee(__('orders.estimate.actions.create'));
        $this->actingAs($staff)->post(route('orders.estimate', $order))->assertRedirect(route('orders.show', $order))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertNotNull($order->customer_quote_id);
        $quote = CustomerQuote::query()->with('lines')->findOrFail($order->customer_quote_id);
        $this->assertSame([$client->id, $order->job_id, $order->id, 'preliminary', 'draft'], [$quote->client_id, $quote->job_id, $quote->order_id, $quote->stage, $quote->status]);

        $lines = $quote->lines->mapWithKeys(fn ($l) => [$l->charge_code => [(float) $l->qty, (int) $l->amount_cents]])->all();
        $this->assertSame([
            'WH-ORDER-DESPATCH' => [1.0, 500],
            'WH-PICK-PLT' => [2.0, 800],
            'WH-PICK-CTN-22-45' => [10.0, 3500],
            'WH-PICK-CTN-LT22' => [4.0, 600],
            'WH-PICK-CTN-GE45' => [2.0, 900],
            'WH-LABEL-OUT' => [18.0, 540],
            'WH-LOAD-PLT' => [2.0, 800],
        ], $lines);
        $this->assertSame([7640, 764, 8404], [$quote->subtotal_cents, $quote->gst_cents, $quote->total_cents]);
        $this->assertTrue($quote->lines->every(fn ($l) => $l->assumptions['missing_rate'] === false && $l->assumptions['is_poa'] === false));

        $this->actingAs($staff)->get(route('orders.show', $order))->assertOk()
            ->assertSee($quote->quote_no)->assertSee('$76.40')->assertSee('$35.00')->assertSee('$5.40')
            ->assertSee(__('orders.estimate.freight_pending'))->assertSee(__('orders.estimate.actions.refresh'))
            ->assertDontSee('WH-ORDER-DESPATCH-URGENT');
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_id' => $staff->id, 'note' => __('orders.estimate.timeline.created', ['quote_no' => $quote->quote_no, 'total' => '$76.40'])]);

        // Warehouse operators do not price orders.
        $this->actingAs($this->staff('warehouse_operator'))->post(route('orders.estimate', $order))->assertForbidden();
    }

    public function test_same_day_orders_after_the_client_cut_off_add_the_urgent_despatch_code(): void
    {
        $client = $this->client(['dispatch_cutoff_time' => '12:00:00']);
        $late = $this->order($client, ['service_level' => 'same_day', 'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 1, 'actual_weight_kg' => 5]]]);
        $early = $this->order($client, ['service_level' => 'same_day', 'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 1, 'actual_weight_kg' => 5]]]);
        DB::table('orders')->where('id', $late->id)->update(['created_at' => today()->setTime(15, 0)]);
        DB::table('orders')->where('id', $early->id)->update(['created_at' => today()->setTime(9, 30)]);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post(route('orders.estimate', $late))->assertRedirect();
        $this->actingAs($admin)->post(route('orders.estimate', $early))->assertRedirect();

        $lateCodes = CustomerQuote::query()->findOrFail($late->fresh()->customer_quote_id)->lines()->pluck('amount_cents', 'charge_code')->all();
        $earlyCodes = CustomerQuote::query()->findOrFail($early->fresh()->customer_quote_id)->lines()->pluck('amount_cents', 'charge_code')->all();
        $this->assertSame(500, $lateCodes['WH-ORDER-DESPATCH']);
        $this->assertSame(1500, $lateCodes['WH-ORDER-DESPATCH-URGENT']); // adds to the standard fee (CHANGE_REQUESTS #5)
        $this->assertArrayNotHasKey('WH-ORDER-DESPATCH-URGENT', $earlyCodes);
        $this->assertSame(500, $earlyCodes['WH-ORDER-DESPATCH']);
    }

    public function test_stocked_pallets_are_picked_per_pallet_transport_preliminary_freight_is_shown_without_cost_and_a_re_estimate_supersedes(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        // 40 cartons received on two pallet units → an order for all 40 cartons is two pallet picks.
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'EST1', 'cartons' => 40, 'weight_kg' => 800, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 20], ['unit_type' => 'pallet', 'carton_qty' => 20]]]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 40]]);
        $this->assertSame('allocated', $order->operational_status);

        // order.confirmed made Transport open the outbound shipment; its preliminary quotes are read-only input for the estimate.
        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
        $carrier = Carrier::query()->create(['code' => 'KAR-T', 'name' => 'Karrio Test Carrier', 'status' => 'active']);
        $this->transportQuote($shipment, $carrier, ['customer_price_cents' => 9000, 'cost_cents' => 6543, 'is_recommended' => true, 'eta_days' => 2]);
        $this->transportQuote($shipment, $carrier, ['customer_price_cents' => 7500, 'cost_cents' => 5432, 'is_cheapest' => true, 'eta_days' => 4]);
        $this->transportQuote($shipment, $carrier, ['customer_price_cents' => 6000, 'cost_cents' => 4321, 'quote_stage' => 'final']); // wrong stage: ignored

        $dispatcher = $this->staff('dispatcher');
        $this->actingAs($dispatcher)->post(route('orders.estimate', $order))->assertRedirect();
        $first = CustomerQuote::query()->with('lines')->findOrFail($order->fresh()->customer_quote_id);
        $this->assertSame(['WH-ORDER-DESPATCH' => 1.0, 'WH-PICK-PLT' => 2.0, 'WH-LABEL-OUT' => 2.0, 'WH-LOAD-PLT' => 2.0, 'TR-DELIVERY-BASE' => 1.0], $first->lines->mapWithKeys(fn ($l) => [$l->charge_code => (float) $l->qty])->all());
        $this->assertSame(500 + 800 + 60 + 800 + 9000, $first->subtotal_cents); // services + the recommended freight as a real line (CHANGE_REQUESTS #68)
        $freightLine = $first->lines->firstWhere('charge_code', 'TR-DELIVERY-BASE');
        $recommendedId = (int) TransportQuote::query()->where('is_recommended', true)->value('id');
        $this->assertSame([9000, $recommendedId, true, 'preliminary', false, false], [(int) $freightLine->amount_cents, (int) $freightLine->transport_quote_id, $freightLine->assumptions['calculation']['pre_priced'], $freightLine->assumptions['quote_stage'], $freightLine->assumptions['missing_rate'], $freightLine->assumptions['is_poa']]);
        $this->assertStringContainsString('Karrio Test Carrier', $freightLine->description);
        $this->assertNull($first->notes); // no more notes snapshot — the line carries the freight
        $this->assertSame(1116, $first->gst_cents); // GST on services and freight alike

        $page = $this->actingAs($dispatcher)->get(route('orders.show', $order))->assertOk();
        $page->assertSee('$21.60')->assertSee('$90.00')->assertSee(__('orders.estimate.freight_flags.recommended'))->assertSee('Karrio Test Carrier')->assertSee('$111.60')->assertSee('$122.76'); // 21.60 services + 90.00 freight, 122.76 incl. GST
        $page->assertDontSee('65.43')->assertDontSee('54.32')->assertDontSee('6543')->assertDontSee('$60.00'); // cost never, final-stage quote never
        $page->assertDontSee(__('orders.estimate.freight_pending'));

        // Re-estimate: a new quote, the previous one is expired through QuoteService and the order points at the new one.
        $this->actingAs($dispatcher)->post(route('orders.estimate', $order))->assertRedirect();
        $order->refresh();
        $second = CustomerQuote::query()->findOrFail($order->customer_quote_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame('draft', $second->status);
        $this->assertSame(2, CustomerQuote::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, $order->events()->where('note', 'like', Str::before(__('orders.estimate.timeline.created'), ':quote_no').'%')->count()); // both estimates are in the timeline
    }

    public function test_client_prices_its_own_order_in_the_portal_and_sees_customer_prices_only(): void
    {
        $client = $this->client();
        $other = $this->client();
        $user = $this->clientUser($client);
        $order = $this->order($client, ['lines' => [['description_cn' => '货架', 'package_type' => 'carton', 'carton_qty' => 6, 'actual_weight_kg' => 60]]]); // 10 kg per carton
        $theirs = $this->order($other);
        $carrier = Carrier::query()->create(['code' => 'OWN-T', 'name' => 'Own Fleet', 'status' => 'active']);
        $shipment = Shipment::query()->create(['shipment_no' => 'SHP-EST-PORTAL', 'job_id' => $order->job_id, 'client_id' => $client->id, 'order_id' => $order->id, 'shipment_type' => 'outbound', 'status' => 'quoted', 'service_level' => 'standard']);
        $this->transportQuote($shipment, $carrier, ['customer_price_cents' => 8250, 'cost_cents' => 7777, 'is_recommended' => true, 'is_cheapest' => true, 'source' => 'own_fleet']);

        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee(__('orders.estimate.actions.create'))->assertSee(__('orders.estimate.none'));
        $this->actingAs($user)->post(route('portal.orders.estimate', $order))->assertRedirect(route('portal.orders.show', $order))->assertSessionHasNoErrors();

        $quote = CustomerQuote::query()->withoutGlobalScopes()->with('lines')->findOrFail($order->fresh()->customer_quote_id);
        $this->assertSame([$client->id, $user->id], [$quote->client_id, $quote->created_by]);
        $this->assertSame(['WH-ORDER-DESPATCH' => 500, 'WH-PICK-CTN-LT22' => 900, 'WH-LABEL-OUT' => 180, 'TR-DELIVERY-BASE' => 8250], $quote->lines->pluck('amount_cents', 'charge_code')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(9830, $quote->subtotal_cents);

        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk();
        $page->assertSee($quote->quote_no)->assertSee('$15.80')->assertSee('$82.50')->assertSee('$98.30')->assertSee(__('orders.estimate.freight_flags.recommended'));
        $page->assertDontSee('77.77')->assertDontSee('7777')->assertDontSee(route('billing.quotes.show', $quote))->assertDontSee(__('orders.estimate.open_quote'));

        // Isolation: another client's order is a 404 in the portal; staff do not price through the portal; clients never reach /orders.
        $this->actingAs($user)->post(route('portal.orders.estimate', $theirs))->assertNotFound();
        $this->assertNull($theirs->fresh()->customer_quote_id);
        $this->actingAs($this->staff('customer_service'))->post(route('portal.orders.estimate', $order))->assertForbidden();
        $this->actingAs($user)->post(route('orders.estimate', $order))->assertForbidden();
    }

    public function test_missing_rates_show_as_pending_never_as_zero(): void
    {
        // A client bound to no rate card at all: every code is a missing rate → every line flagged, totals show 待报价, never $0.00.
        $client = $this->client(['standard_rate_card_id' => null]);
        $order = $this->order($client, ['lines' => [['description_cn' => '货物', 'package_type' => 'carton', 'carton_qty' => 3, 'actual_weight_kg' => 30]]]);
        $admin = $this->staff('admin');

        $this->actingAs($admin)->post(route('orders.estimate', $order))->assertRedirect();
        $quote = CustomerQuote::query()->with('lines')->findOrFail($order->fresh()->customer_quote_id);
        $this->assertTrue($quote->lines->every(fn ($l) => $l->assumptions['missing_rate'] === true));

        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()
            ->assertSee(__('orders.estimate.flags.missing'))->assertDontSee('$0.00')
            ->assertSee(__('orders.estimate.unpriced_note', ['count' => $quote->lines->count()]));
    }

    private function order(Client $client, array $overrides = []): Order
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        return app(OrderCreationService::class)->create(array_replace([
            'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'EST-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3, 'actual_weight_kg' => 30]],
        ], $overrides), null, 'manual');
    }

    private function transportQuote(Shipment $shipment, Carrier $carrier, array $attributes): TransportQuote
    {
        return TransportQuote::query()->create($attributes + [
            'shipment_id' => $shipment->id, 'carrier_id' => $carrier->id, 'source' => 'karrio', 'service_level' => 'standard',
            'cost_cents' => 1000, 'customer_price_cents' => 1200, 'markup_percent' => 20, 'eta_days' => 3,
            'is_recommended' => false, 'is_cheapest' => false, 'is_fastest' => false, 'quote_stage' => 'preliminary', 'status' => 'quoted',
            'quoted_at' => now(), 'expires_at' => now()->addDay(),
        ]);
    }
}
