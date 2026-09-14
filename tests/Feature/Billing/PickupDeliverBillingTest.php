<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\ChargeRule;
use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Transport\Services\ShipmentIntakeService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Modules\Transport\Services\TransportOptionService;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Lead decision 2026-09-14 (CHANGE_REQUESTS #120, "按默认"): a 提货直送 (pickup_deliver) order — collected at the client's pickup
 * address and delivered, never in stock, never packed — is billed the SAME handling standards as a from_stock outbound order,
 * at the moment the final transport plan is confirmed (`shipment.quote_confirmed`, the event that already bills freight /
 * tailgate / fuel and drafts the per_job invoice), from the client's DECLARED packages (per-piece weight decides the carton
 * band). The estimate shows the same lines next to freight. from_stock shipments are untouched: their handling still comes
 * from outbound.packed. TR-PICKUP exists as a manual-only code until it is priced.
 */
class PickupDeliverBillingTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** Own-fleet fixed cost the stub adapter answers with — a $75.00 delivery. */
    public const OWN_FLEET_COST = 7500;

    /** The handling lines the declared packages (2 × pallet 300 kg, 3 × carton 10 kg, 1 × carton 30 kg) produce on the Edward card. */
    private const HANDLING = [
        'WH-ORDER-DESPATCH' => 500,   // 1 order × $5.00
        'WH-PICK-PLT' => 800,         // 2 pallets × $4.00
        'WH-PICK-CTN-LT22' => 450,    // 3 cartons × $1.50 (10 kg)
        'WH-PICK-CTN-22-45' => 350,   // 1 carton × $3.50 (30 kg)
        'WH-LABEL-OUT' => 180,        // 6 pieces × $0.30
        'WH-LOAD-PLT' => 800,         // 2 pallets × $4.00
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $own = Carrier::query()->create(['code' => 'OWN-PDB', 'name' => 'Edward Own Fleet', 'status' => 'active']);
        CarrierService::query()->create(['carrier_id' => $own->id, 'source' => 'own_fleet', 'service_level' => 'standard', 'default_eta_days' => 1, 'active' => true]);

        // Quotes without Karrio: one own-fleet adapter at a fixed cost is enough for the transport side of the flow.
        $service = new TransportOptionService(
            [$this->ownFleetAdapter()],
            app(ShipmentQuoteRequestFactory::class), app(RateService::class), app(ExceptionService::class), app(QuoteSelectionService::class),
        );
        $this->app->instance(TransportOptionService::class, $service);
        $this->app->instance(TransportOptionServiceContract::class, $service);
    }

    public function test_a_pickup_deliver_order_is_estimated_and_billed_with_the_from_stock_handling_codes_when_its_final_quote_is_confirmed(): void
    {
        $client = $this->client(['default_markup_percent' => 0, 'invoice_mode' => 'per_job']);
        $this->freightCard($client);
        $user = $this->clientUser($client);
        $form = $this->pickupForm();

        // 获取估价: the handling lines from the declared packages, next to the freight option.
        $preview = $this->actingAs($user)->post(route('portal.orders.preview'), $form)->assertOk();
        foreach (self::HANDLING as $code => $cents) {
            $preview->assertSee($code)->assertSee('$'.number_format($cents / 100, 2));
        }
        $preview->assertSee('Edward Own Fleet')->assertSee('$75.00')->assertDontSee('WH-ORDER-DESPATCH-URGENT')->assertDontSee(__('portal.estimate.preview_poa'));

        // The order is placed: the stored estimate (customer quote) carries the same lines; no freight line since no option was ticked.
        $this->actingAs($user)->post(route('portal.orders.store'), $form)->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['pickup_deliver', 3], [$order->order_type, $order->declaredPackages()->count()]);
        $quote = CustomerQuote::query()->withoutGlobalScopes()->with('lines')->findOrFail($order->customer_quote_id);
        $this->assertEquals(self::HANDLING, $quote->lines->groupBy('charge_code')->map(fn ($lines) => (int) $lines->sum('amount_cents'))->all());
        $this->assertSame(array_sum(self::HANDLING), (int) $quote->subtotal_cents);
        $this->assertSame(0, Charge::query()->count()); // an estimate is never a charge

        // Customer service confirms → Transport opens the shipment and quotes FINAL straight away (pure transport: no stock, no packing).
        $cs = $this->staff('customer_service');
        app(OrderStatusService::class)->transitionOperational($order, 'confirmed', $cs->id);
        app(OutboxDispatcher::class)->dispatchDue();
        $shipment = Shipment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('quoted', $shipment->status);
        $this->assertSame(0, TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'preliminary')->count());
        $final = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('source', 'own_fleet')->sole();
        $this->assertSame(self::OWN_FLEET_COST, (int) $final->customer_price_cents);
        $this->assertSame(0, Charge::query()->count()); // nothing is billed before the plan is confirmed

        // The client confirms the plan: shipment.quote_confirmed carries the declared packages as billing lines (additive keys, CR #120).
        $this->actingAs($user)->post(route('portal.orders.quotes.confirm', [$order, $final->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('quote_confirmed', $shipment->fresh()->status);
        $event = OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole();
        $payload = $event->payload;
        $this->assertSame(['pickup_deliver', false, 2, 4, 6], [$payload['order_type'], $payload['is_urgent'], $payload['pallet_count'], $payload['carton_count'], $payload['label_count']]);
        $this->assertEquals(
            [['pallet', 'pallet', 2, 300.0], ['carton', 'carton', 3, 10.0], ['carton', 'carton', 1, 30.0]],
            array_map(fn (array $line) => [$line['package_type'], $line['unit_type'], (int) $line['qty'], (float) $line['unit_weight_kg']], $payload['lines']),
        );
        $this->assertTrue($payload['tailgate_required']); // 300 kg pallets
        $this->assertSame($order->id, $payload['order_id']);

        // Billing: the six handling codes at the standard card's prices, once each, keyed by the order — plus freight, tailgate and fuel as before.
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $charges = Charge::query()->with('chargeCode')->where('job_id', $order->job_id)->get();
        $byCode = $charges->groupBy('chargeCode.code')->map(fn ($c) => [$c->count(), (int) $c->sum('amount_cents')]);
        $expected = array_map(fn (int $cents) => [1, $cents], self::HANDLING) + ['TR-DELIVERY-BASE' => [1, self::OWN_FLEET_COST], 'TR-TAILGATE' => [1, 4500], 'TR-FUEL' => [1, 750]];
        $this->assertEquals($expected, $byCode->all());

        $handling = $charges->filter(fn (Charge $c) => str_starts_with($c->chargeCode->code, 'WH-'))->keyBy('chargeCode.code');
        $this->assertEquals(['WH-ORDER-DESPATCH' => 1.0, 'WH-PICK-PLT' => 2.0, 'WH-PICK-CTN-LT22' => 3.0, 'WH-PICK-CTN-22-45' => 1.0, 'WH-LABEL-OUT' => 6.0, 'WH-LOAD-PLT' => 2.0], $handling->map(fn (Charge $c) => (float) $c->qty)->all());
        foreach ($handling as $code => $charge) {
            $this->assertSame(
                ["order:{$order->id}", 1, 'shipment', $shipment->id, 'pending', $client->id],
                [$charge->source_activity_id, (int) $charge->activity_version, $charge->source_type, (int) $charge->source_id, $charge->status, (int) $charge->client_id],
                "charge {$code}",
            );
            $this->assertSame('standard', $charge->calculation_snapshot_json['card'], "charge {$code} is priced from the standard card");
        }
        $this->assertDatabaseMissing('charges', ['charge_code_id' => ChargeCode::query()->where('code', 'WH-ORDER-DESPATCH-URGENT')->value('id')]); // requested in 5 days: not urgent
        $this->assertDatabaseMissing('charges', ['charge_code_id' => ChargeCode::query()->where('code', 'TR-PICKUP')->value('id')]);          // manual only until priced
        $this->assertDatabaseMissing('exceptions', ['type' => 'missing_rate']);

        // §6.8 #12: the per_job service invoice draft appears on the same event, with every priced line on it.
        $draft = Invoice::query()->withoutGlobalScopes()->where('status', 'draft')->sole();
        $this->assertSame([$client->id, 'service', (int) $charges->sum('amount_cents'), $charges->count()], [(int) $draft->client_id, $draft->invoice_type, (int) $draft->subtotal_cents, $draft->lines()->count()]);
        $this->assertSame(array_sum(self::HANDLING) + self::OWN_FLEET_COST + 4500 + 750, (int) $draft->subtotal_cents);

        // Replaying the same envelope (a retried consumer, a second dispatch run) never bills the order twice: unique key order:{id}, version 1.
        app(ChargeEngine::class)->applyEvent(['event_name' => 'shipment.quote_confirmed', 'job_id' => $order->job_id, 'client_id' => $client->id, 'payload' => $payload]);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame($charges->count(), Charge::query()->count());
        $this->assertSame(1, Invoice::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Charge::query()->where('source_activity_id', "order:{$order->id}")->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-ORDER-DESPATCH'))->count());
    }

    public function test_a_from_stock_order_gets_no_handling_charges_when_its_quote_is_confirmed(): void
    {
        $client = $this->client(['default_markup_percent' => 0, 'invoice_mode' => 'per_job']);
        $this->freightCard($client);
        $user = $this->clientUser($client);
        $this->warehouse();

        $this->actingAs($user)->post(route('portal.orders.store'), $this->fromStockForm())->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->withoutGlobalScopes()->sole();
        app(OrderStatusService::class)->transitionOperational($order, 'confirmed', $this->staff('customer_service')->id);
        app(OutboxDispatcher::class)->dispatchDue(); // preliminary quotes
        $shipment = Shipment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('quoted', $shipment->status);

        // Packed → final quotes; nothing was chosen with the 估价, so the client confirms the own-fleet plan in the portal.
        app(ShipmentIntakeService::class)->fromPackedOutbound($this->packedEnvelope($order, 501));
        $final = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('source', 'own_fleet')->sole();
        $this->actingAs($user)->post(route('portal.orders.quotes.confirm', [$order, $final->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('quote_confirmed', $shipment->fresh()->status);

        // The event names its order type but carries none of the handling keys: those belong to outbound.packed for stock orders.
        $payload = OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole()->payload;
        $this->assertSame('from_stock', $payload['order_type']);
        foreach (['is_urgent', 'lines', 'pallet_count', 'carton_count', 'label_count'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }

        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $byCode = Charge::query()->with('chargeCode')->get()->mapWithKeys(fn (Charge $c) => [$c->chargeCode->code => (int) $c->amount_cents]);
        $this->assertEquals(['TR-DELIVERY-BASE' => self::OWN_FLEET_COST, 'TR-FUEL' => 750], $byCode->all()); // freight and fuel only; 10 kg cartons to a business: no tailgate
        $this->assertSame(0, Charge::query()->where('source_activity_id', 'like', 'order:%')->count());
        $this->assertDatabaseMissing('exceptions', ['type' => 'missing_rate']);
    }

    public function test_the_quote_confirmed_rules_bill_an_urgent_pickup_order_and_leave_from_stock_and_legacy_payloads_alone(): void
    {
        $client = $this->client(['default_markup_percent' => 0]);
        $this->freightCard($client);
        $job = app(JobService::class)->create($client->id, 'transport_only')['job_id'];
        $engine = app(ChargeEngine::class);
        $envelope = fn (int $n, array $extra) => ['event_name' => 'shipment.quote_confirmed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => $extra + [
            'shipment_id' => 700 + $n, 'shipment_no' => 'SHP-PDB-'.$n, 'shipment_type' => 'outbound', 'order_id' => 800 + $n, 'client_id' => $client->id, 'job_id' => $job,
            'source' => 'own_fleet', 'pricing_mode' => 'fixed', 'quote_stage' => 'final', 'cost_cents' => self::OWN_FLEET_COST, 'customer_price_cents' => self::OWN_FLEET_COST,
            'tailgate_required' => false, 'zone' => '3175', 'packages' => ['count' => 1, 'total_weight_kg' => 50, 'total_cbm' => 0.06], 'confirmed_by_type' => 'coordinator',
        ]];
        $handling = fn (int $orderId) => Charge::query()->with('chargeCode')->where('source_activity_id', "order:{$orderId}")->get()->mapWithKeys(fn (Charge $c) => [$c->chargeCode->code => (int) $c->amount_cents])->all();

        // Urgent 提货直送 (requested today, confirmed after the cut-off): the $5 processing fee AND the $15 urgent fee, a ≥ 45 kg carton pick, one label; no pallet lines.
        $engine->applyEvent($envelope(1, [
            'order_type' => 'pickup_deliver', 'is_urgent' => true, 'pallet_count' => 0, 'carton_count' => 1, 'label_count' => 1,
            'lines' => [['package_type' => 'carton', 'unit_type' => 'carton', 'qty' => 1, 'unit_weight_kg' => 50.0]],
        ]));
        $this->assertEquals(['WH-ORDER-DESPATCH' => 500, 'WH-ORDER-DESPATCH-URGENT' => 1500, 'WH-PICK-CTN-GE45' => 450, 'WH-LABEL-OUT' => 30], $handling(801));

        // A from_stock shipment names its type and carries no handling keys → freight only.
        $engine->applyEvent($envelope(2, ['order_type' => 'from_stock', 'fulfilment_id' => 12]));
        $this->assertSame([], $handling(802));

        // An event recorded before CR #120 (no order_type at all), even one that happens to carry lines / label_count, is never billed as a pickup order.
        $engine->applyEvent($envelope(3, ['lines' => [['unit_type' => 'pallet', 'qty' => 1, 'unit_weight_kg' => 400]], 'label_count' => 4, 'pallet_count' => 1]));
        $this->assertSame([], $handling(803));

        $this->assertSame(3, Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'TR-DELIVERY-BASE'))->count()); // freight billed for all three as before
        $this->assertSame(4, Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'like', 'WH-%'))->count()); // the urgent pickup order's four, nothing else
        $this->assertDatabaseMissing('exceptions', ['type' => 'missing_rate']);
    }

    public function test_tr_pickup_is_a_manual_only_transport_code_without_a_standard_rate(): void
    {
        $client = $this->client();
        $code = ChargeCode::query()->where('code', 'TR-PICKUP')->firstOrFail();
        $this->assertSame(['transport', 'delivery', 'Collection at sender (surcharge)', true], [$code->category, $code->default_uom, $code->customer_description, (bool) $code->active]);
        $this->assertSame(['manual'], ChargeRule::query()->where('charge_code_id', $code->id)->pluck('trigger_event')->unique()->values()->all()); // never auto-billed
        $this->assertSame(0, RateItem::query()->where('charge_code_id', $code->id)->whereIn('rate_card_id', RateCard::query()->where('is_standard', true)->pluck('id'))->count()); // no Edward row

        // Finance adds it by hand with a reason and an amount until it is priced; without an amount it waits for review — never $0.
        $job = app(JobService::class)->create($client->id, 'transport_only')['job_id'];
        $finance = $this->staff('finance');
        $engine = app(ChargeEngine::class);
        $priced = $engine->manual($job, $client->id, 'TR-PICKUP', 1, 'collection at the supplier', 2500, $finance->id);
        $this->assertSame([2500, 'pending', true], [(int) $priced->amount_cents, $priced->status, (bool) $priced->is_manual]);
        $unpriced = $engine->manual($job, $client->id, 'TR-PICKUP', 1, 'collection at the supplier', null, $finance->id);
        $this->assertSame('needs_review', $unpriced->status);
    }

    /** A client card pricing the transport codes (the Edward standard card carries no TR rows): cost_plus at the client's 0 % markup, tailgate $45.00, fuel 10 %. */
    private function freightCard(Client $client): RateCard
    {
        $codes = ChargeCode::query()->pluck('id', 'code');
        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'freight', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'cost_plus']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-TAILGATE'], 'pricing_mode' => 'fixed', 'rate_cents' => 4500]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-FUEL'], 'pricing_mode' => 'percent', 'markup_percent' => 10]);

        return $card;
    }

    /** @return array<string, mixed> the portal 提货直送 form: pickup address + declared packages, no goods lines, requested in 5 days */
    private function pickupForm(): array
    {
        return [
            'order_type' => 'pickup_deliver', 'external_ref' => 'PDB-'.uniqid(),
            'deliver_to_name' => 'Receiver Pty Ltd', 'deliver_to_phone' => '0400 000 000', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Dandenong', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3175', 'deliver_to_address_type' => 'business',
            'requested_date' => today()->addDays(5)->toDateString(), 'service_level' => 'standard',
            'pickup_name' => 'Factory', 'pickup_phone' => '0400 000 001', 'pickup_address_line' => '9 Supplier Rd', 'pickup_suburb' => 'Laverton', 'pickup_state' => 'VIC', 'pickup_postcode' => '3028',
            'declared_packages' => [
                ['package_type' => 'pallet', 'qty' => 2, 'weight_kg' => 300, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400],
                ['package_type' => 'carton', 'qty' => 3, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250],
                ['package_type' => 'carton', 'qty' => 1, 'weight_kg' => 30, 'length_mm' => 500, 'width_mm' => 400, 'height_mm' => 300],
            ],
        ];
    }

    /** @return array<string, mixed> a from_stock portal order: four 10 kg cartons with dimensions to a business address */
    private function fromStockForm(): array
    {
        return [
            'order_type' => 'from_stock', 'external_ref' => 'PDB-FS-'.uniqid(),
            'deliver_to_name' => 'Amazon FBA BWU2', 'deliver_to_phone' => '0400 000 000', 'deliver_to_address' => '1 Warehouse Rd', 'deliver_to_suburb' => 'Moorebank', 'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2170', 'deliver_to_address_type' => 'business',
            'requested_date' => today()->addDays(5)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Bluetooth speakers', 'package_type' => 'carton', 'carton_qty' => 4, 'actual_weight_kg' => 40, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
        ];
    }

    /** @return array<string, mixed> the Warehouse outbound.packed envelope Transport quotes the final stage from */
    private function packedEnvelope(Order $order, int $fulfilmentId): array
    {
        return ['event_name' => 'outbound.packed', 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'correlation_id' => $order->order_no, 'payload' => [
            'order_id' => $order->id, 'order_no' => $order->order_no, 'fulfilment_id' => $fulfilmentId, 'client_id' => $order->client_id, 'job_id' => $order->job_id,
            'packages' => [['carton_label' => 'PKG-1', 'package_type' => 'carton', 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
        ]];
    }

    private function ownFleetAdapter(): CarrierAdapter
    {
        return new class implements CarrierAdapter
        {
            public function source(): string
            {
                return 'own_fleet';
            }

            public function capabilities(): array
            {
                return ['quote' => true, 'book' => true, 'cancel' => true, 'label' => false, 'tracking' => 'none', 'pod' => 'manual'];
            }

            public function quote(array $request): array
            {
                return [[
                    'service_code' => 'own.standard', 'service_name' => 'own.standard', 'service_level' => 'standard',
                    'cost_cents' => PickupDeliverBillingTest::OWN_FLEET_COST, 'eta_days' => 1, 'pickup_dates' => [], 'raw' => ['pricing_mode' => 'fixed'],
                ]];
            }

            public function book(array $request, string $serviceCode, array $options = []): array
            {
                return ['booking_ref' => 'PDB-1', 'tracking_number' => null, 'label_path' => null, 'status' => 'booked', 'raw' => []];
            }

            public function cancel(string $bookingRef): bool
            {
                return true;
            }

            public function label(string $bookingRef): ?string
            {
                return null;
            }

            public function tracking(string $bookingRef): array
            {
                return [];
            }
        };
    }
}
