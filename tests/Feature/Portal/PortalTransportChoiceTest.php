<?php

namespace Tests\Feature\Portal;

use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\MasterData\Models\Carrier;
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
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Lead decision 2026-09-11 (CHANGE_REQUESTS #118): the client decides the transport plan. The 获取估价 screen lists the transport
 * options priced for the order next to the warehouse fees, the client ticks one, the choice travels with the order and its estimate,
 * Transport selects the matching preliminary quote as the client's own decision and confirms the final quote automatically while the
 * measured price stays within tolerance — the dispatcher executes, and only steps in (代客确认) when the option changed too much.
 */
class PortalTransportChoiceTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** @var array<string, int> adjustable carrier costs, so the final quote can drift from the estimate */
    public static array $costs = ['own_fleet' => 7500, 'karrio' => 8250];

    protected function setUp(): void
    {
        parent::setUp();
        self::$costs = ['own_fleet' => 7500, 'karrio' => 8250];
        $own = Carrier::query()->create(['code' => 'OWN-TC', 'name' => 'Edward Own Fleet', 'status' => 'active']);
        $karrio = Carrier::query()->create(['code' => 'KAR-TC', 'name' => 'Demo Freight', 'status' => 'active']);
        CarrierService::query()->create(['carrier_id' => $own->id, 'source' => 'own_fleet', 'service_level' => 'standard', 'default_eta_days' => 1, 'active' => true]);
        CarrierService::query()->create(['carrier_id' => $karrio->id, 'source' => 'karrio', 'service_level' => 'express', 'default_eta_days' => 1, 'active' => true]);

        $service = new TransportOptionService(
            [$this->adapter('own_fleet', 'standard', 'own.standard'), $this->adapter('karrio', 'express', 'demo_express')],
            app(ShipmentQuoteRequestFactory::class), app(RateService::class), app(ExceptionService::class), app(QuoteSelectionService::class),
        );
        $this->app->instance(TransportOptionService::class, $service);
        $this->app->instance(TransportOptionServiceContract::class, $service);
    }

    public function test_client_sees_transport_options_with_the_estimate_picks_one_and_transport_confirms_it_on_its_own(): void
    {
        $client = $this->client(['default_markup_percent' => 20]); // karrio 8250 cost × 1.2 = $99.00 customer price; own fleet is a fixed $75.00
        $user = $this->clientUser($client);
        $this->warehouse();
        $form = $this->form();

        // 获取估价: warehouse fees plus the two transport options, own fleet recommended (cheapest, meets the requested date).
        $preview = $this->actingAs($user)->post(route('portal.orders.preview'), $form)->assertOk();
        $preview->assertSee(__('portal.estimate.transport_title'))->assertSee('Edward Own Fleet')->assertSee('Demo Freight')->assertSee('$75.00')->assertSee('$99.00')
            ->assertSee('name="transport_choice"', false)->assertSee('value="own_fleet|standard|', false)->assertSee('value="karrio|express|', false)
            ->assertSee(__('orders.estimate.freight_flags.recommended'))->assertSee(__('portal.estimate.preview_freight_selected'))
            ->assertDontSee('8250')->assertDontSee('82.50'); // cost never
        $this->assertSame(0, TransportQuote::query()->count(), 'the estimate writes no quote rows');
        $karrioKey = 'karrio|express|'.Carrier::query()->where('code', 'KAR-TC')->value('id');

        // The client picks the faster Karrio option and submits: the choice and the freight line travel with the order.
        $this->actingAs($user)->post(route('portal.orders.store'), $form + ['transport_choice' => $karrioKey])->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->withoutGlobalScopes()->sole();
        $preference = $order->transport_preference;
        $this->assertSame(['karrio', 'express', 9900, 'Demo Freight', $user->id], [$preference['source'], $preference['service_level'], $preference['customer_price_cents'], $preference['carrier_name'], $preference['chosen_by']]);
        $this->assertArrayNotHasKey('cost_cents', $preference);
        $quote = CustomerQuote::query()->withoutGlobalScopes()->with('lines')->findOrFail($order->customer_quote_id);
        $freight = $quote->lines->firstWhere('charge_code', 'TR-DELIVERY-BASE');
        $this->assertSame([9900, null], [(int) $freight->amount_cents, $freight->transport_quote_id]);
        $this->assertStringContainsString('Demo Freight', $freight->description);
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee(__('portal.quotes.client_choice'))->assertSee('Demo Freight')->assertSee('$99.00');

        // Customer service confirms the order → Transport quotes preliminary and selects the client's option as the client's own decision.
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.estimate.client_choice'));
        app(OrderStatusService::class)->transitionOperational($order, 'confirmed', $cs->id);
        app(OutboxDispatcher::class)->dispatchDue();
        $shipment = Shipment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('quoted', $shipment->status);
        $selected = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'preliminary')->where('status', 'selected')->sole();
        $this->assertSame(['karrio', 'express', 9900, 'client', $user->id], [$selected->source, $selected->service_level, $selected->customer_price_cents, $selected->selected_by, $selected->selected_by_user_id]);
        $this->assertSame($selected->id, $shipment->selected_quote_id);
        $this->actingAs($this->staff('dispatcher'))->get(route('transport.shipments.show', $shipment))->assertOk()->assertSee(__('transport.quotes.client_preference'))->assertSee('Demo Freight');

        // Packed: the final Karrio quote is the same price → confirmed automatically in the client's name; nobody had to click.
        app(ShipmentIntakeService::class)->fromPackedOutbound($this->packedEnvelope($order, 501));
        $shipment->refresh();
        $final = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'selected')->sole();
        $this->assertSame(['quote_confirmed', $final->id, 'karrio', 'express', 'client', $user->id], [$shipment->status, $shipment->selected_quote_id, $final->source, $final->service_level, $final->selected_by, $final->selected_by_user_id]);
        $event = OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole();
        $this->assertSame(['client', $user->id, 9900], [$event->payload['confirmed_by_type'], $event->payload['confirmed_by'], $event->payload['customer_price_cents']]);
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee(__('portal.quotes.confirmed_choice'))->assertSee(__('portal.quotes.confirmed_by.client'))->assertDontSee(__('portal.quotes.confirm'));
    }

    public function test_a_final_price_outside_the_tolerance_waits_for_the_client_who_sees_its_own_choice_marked(): void
    {
        $client = $this->client(['default_markup_percent' => 20]);
        $user = $this->clientUser($client);
        $this->warehouse();
        $ownKey = 'own_fleet|standard|'.Carrier::query()->where('code', 'OWN-TC')->value('id');
        $this->actingAs($user)->post(route('portal.orders.store'), $this->form() + ['transport_choice' => $ownKey])->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['own_fleet', 7500], [$order->transport_preference['source'], $order->transport_preference['customer_price_cents']]);

        $cs = $this->staff('customer_service');
        app(OrderStatusService::class)->transitionOperational($order, 'confirmed', $cs->id);
        app(OutboxDispatcher::class)->dispatchDue();
        $shipment = Shipment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('own_fleet', TransportQuote::query()->where('shipment_id', $shipment->id)->where('status', 'selected')->sole()->source);

        // The measured parcels price own fleet 40% higher than the estimate → not confirmed; the client must re-confirm in the portal.
        self::$costs['own_fleet'] = 10500;
        app(ShipmentIntakeService::class)->fromPackedOutbound($this->packedEnvelope($order, 502));
        $shipment->refresh();
        $this->assertSame(['quoted', null], [$shipment->status, $shipment->selected_quote_id]);
        $this->assertDatabaseMissing('outbox_events', ['event_name' => 'shipment.quote_confirmed']);

        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk();
        $page->assertSee(__('portal.quotes.confirm'))->assertSee(__('portal.quotes.your_choice'))->assertSee('$105.00')->assertSee('$99.00');
        $ownFinal = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('source', 'own_fleet')->sole();
        $this->actingAs($user)->post(route('portal.orders.quotes.confirm', [$order, $ownFinal->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['quote_confirmed', $ownFinal->id], [$shipment->fresh()->status, $shipment->fresh()->selected_quote_id]);
        $this->assertSame('client', $ownFinal->fresh()->selected_by);
    }

    public function test_without_weights_and_dimensions_the_form_explains_why_no_transport_option_is_priced(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $this->warehouse();
        $form = $this->form();
        $form['lines'] = [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3]];

        $this->actingAs($user)->post(route('portal.orders.preview'), $form)->assertOk()
            ->assertSee(__('portal.estimate.transport_none.no_items'))->assertDontSee('id="transport-options"', false)->assertSee(__('portal.estimate.preview_total'));
        $this->actingAs($user)->post(route('portal.orders.store'), $form)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull(Order::query()->withoutGlobalScopes()->sole()->transport_preference);
    }

    /** @return array<string, mixed> */
    private function form(): array
    {
        return [
            'order_type' => 'from_stock', 'deliver_to_name' => 'Amazon FBA BWU2', 'deliver_to_phone' => '0400 000 000', 'deliver_to_address' => '1 Warehouse Rd',
            'deliver_to_suburb' => 'Moorebank', 'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2170', 'deliver_to_address_type' => 'business',
            'requested_date' => today()->addDays(5)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Bluetooth speakers', 'package_type' => 'carton', 'carton_qty' => 4, 'actual_weight_kg' => 40, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
        ];
    }

    /** @return array<string, mixed> */
    private function packedEnvelope(Order $order, int $fulfilmentId): array
    {
        return ['event_name' => 'outbound.packed', 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'correlation_id' => $order->order_no, 'payload' => [
            'order_id' => $order->id, 'order_no' => $order->order_no, 'fulfilment_id' => $fulfilmentId, 'client_id' => $order->client_id, 'job_id' => $order->job_id,
            'packages' => [['carton_label' => 'PKG-1', 'package_type' => 'carton', 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
        ]];
    }

    private function adapter(string $source, string $level, string $code): CarrierAdapter
    {
        return new class($source, $level, $code) implements CarrierAdapter
        {
            public function __construct(private readonly string $source, private readonly string $level, private readonly string $code) {}

            public function source(): string
            {
                return $this->source;
            }

            public function capabilities(): array
            {
                return ['quote' => true, 'book' => true, 'cancel' => true, 'label' => false, 'tracking' => 'none', 'pod' => 'manual'];
            }

            public function quote(array $request): array
            {
                return [[
                    'service_code' => $this->code, 'service_name' => $this->code, 'service_level' => $this->level,
                    'cost_cents' => PortalTransportChoiceTest::$costs[$this->source], 'eta_days' => 1, 'pickup_dates' => [], 'raw' => ['pricing_mode' => 'fixed'],
                ]];
            }

            public function book(array $request, string $serviceCode, array $options = []): array
            {
                return ['booking_ref' => 'TC-1', 'tracking_number' => null, 'label_path' => null, 'status' => 'booked', 'raw' => []];
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
