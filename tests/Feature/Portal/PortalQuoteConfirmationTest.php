<?php

namespace Tests\Feature\Portal;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * ERP_PLAN §5.7 #2 / §7 step 4: the client confirms the recommended final quote or picks another one in the portal.
 * The confirmation is Transport's QuoteSelectionService (client actor validated there); the client never sees cost or markup.
 */
class PortalQuoteConfirmationTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_client_sees_final_quotes_in_customer_prices_and_confirms_one(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $order = $this->order($client);
        $preliminary = $this->shipment($order, 'SHP-PRE', null, 'quoted');
        $batch = $this->shipment($order, 'SHP-F1', 1, 'quoted');
        $own = Carrier::query()->create(['code' => 'OWN-PQ', 'name' => 'Edward Own Fleet', 'status' => 'active']);
        $karrio = Carrier::query()->create(['code' => 'KAR-PQ', 'name' => 'Karrio Express', 'status' => 'active']);
        $recommended = $this->quote($batch, $own, ['customer_price_cents' => 7500, 'cost_cents' => 7500, 'eta_days' => 1, 'is_recommended' => true]);
        $cheapest = $this->quote($batch, $karrio, ['source' => 'karrio', 'customer_price_cents' => 6000, 'cost_cents' => 4321, 'markup_percent' => 38.85, 'eta_days' => 4, 'is_cheapest' => true]);
        $this->quote($batch, $karrio, ['source' => 'karrio', 'service_level' => 'express', 'customer_price_cents' => 9900, 'cost_cents' => 8000, 'eta_days' => 1, 'is_fastest' => true]);
        $this->quote($preliminary, $own, ['quote_stage' => 'preliminary', 'customer_price_cents' => 7000, 'cost_cents' => 7000]); // the estimate's quote — not confirmable here

        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk();
        $page->assertSee(__('portal.quotes.title'))->assertSee('SHP-F1') // the batch shipment carries the final quotes (the preliminary one is only in the tracking list)
            ->assertSee('Edward Own Fleet')->assertSee('Karrio Express')->assertSee('$75.00')->assertSee('$60.00')->assertSee('$99.00')
            ->assertSee(__('orders.estimate.freight_flags.recommended'))->assertSee(__('orders.estimate.freight_flags.cheapest'))->assertSee(__('orders.estimate.freight_flags.fastest'))
            ->assertSee(__('portal.quotes.confirm'))->assertSee(route('portal.orders.quotes.confirm', [$order, $cheapest->id]))
            ->assertDontSee('43.21')->assertDontSee('4321')->assertDontSee('38.85')->assertDontSee('$70.00'); // cost, markup and the preliminary price never reach the client

        // 改选: the client picks the cheapest instead of the recommended quote.
        $this->actingAs($user)->post(route('portal.orders.quotes.confirm', [$order, $cheapest->id]))
            ->assertRedirect(route('portal.orders.show', $order))->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('portal.quotes.confirmed', ['shipment_no' => 'SHP-F1']));

        $batch->refresh();
        $this->assertSame(['quote_confirmed', $cheapest->id, $karrio->id, 'standard'], [$batch->status, $batch->selected_quote_id, $batch->carrier_id, $batch->service_level]);
        $this->assertSame(['selected', 'client', $user->id], [$cheapest->fresh()->status, $cheapest->fresh()->selected_by, $cheapest->fresh()->selected_by_user_id]);
        $this->assertSame('quoted', $recommended->fresh()->status);
        $event = OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole();
        $this->assertSame([$batch->id, $cheapest->id, 'final', 'client', $user->id, 6000, $client->id, $order->id], [
            $event->payload['shipment_id'], $event->payload['transport_quote_id'], $event->payload['quote_stage'], $event->payload['confirmed_by_type'],
            $event->payload['confirmed_by'], $event->payload['customer_price_cents'], $event->payload['client_id'], $event->payload['order_id'],
        ]);

        // Afterwards the page shows the confirmed choice and no more confirm buttons; confirming again is a no-op.
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()
            ->assertSee(__('portal.quotes.confirmed_choice'))->assertSee(__('portal.quotes.confirmed_by.client'))->assertSee('Karrio Express')->assertSee('$60.00')
            ->assertDontSee(__('portal.quotes.confirm'))->assertDontSee('$75.00');
        $this->actingAs($user)->post(route('portal.orders.quotes.confirm', [$order, $cheapest->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->count());
        // The recommended one can no longer be chosen once the shipment is confirmed — Transport's rule, surfaced as a form error.
        $this->actingAs($user)->from(route('portal.orders.show', $order))->post(route('portal.orders.quotes.confirm', [$order, $recommended->id]))
            ->assertRedirect(route('portal.orders.show', $order))->assertSessionHasErrors(['quote' => __('transport.selection.invalid_status')]);
        // 2026-09-10 rule (每一处报错都用中文): the refusal the client reads on the page is the Chinese sentence, not an exception name.
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()
            ->assertSee(__('transport.selection.invalid_status'))->assertDontSee('DomainException')->assertDontSee('Exception');
    }

    public function test_another_clients_quote_is_a_404_and_staff_are_not_portal_users(): void
    {
        $client = $this->client();
        $other = $this->client();
        $user = $this->clientUser($client);
        $mine = $this->order($client);
        $theirs = $this->order($other);
        $carrier = Carrier::query()->create(['code' => 'OWN-PQ2', 'name' => 'Own', 'status' => 'active']);
        $mineQuote = $this->quote($this->shipment($mine, 'SHP-MINE', 1, 'quoted'), $carrier, ['is_recommended' => true]);
        $theirsQuote = $this->quote($this->shipment($theirs, 'SHP-THEIRS', 1, 'quoted'), $carrier, ['is_recommended' => true]);

        $this->actingAs($user)->get(route('portal.orders.show', $theirs))->assertNotFound();
        $this->actingAs($user)->post(route('portal.orders.quotes.confirm', [$theirs, $theirsQuote->id]))->assertNotFound();
        $this->actingAs($user)->post(route('portal.orders.quotes.confirm', [$mine, $theirsQuote->id]))->assertNotFound(); // own order, foreign quote
        $this->assertSame('quoted', $theirsQuote->fresh()->status);
        $this->assertDatabaseMissing('outbox_events', ['event_name' => 'shipment.quote_confirmed']);

        $this->actingAs($this->staff('customer_service'))->post(route('portal.orders.quotes.confirm', [$mine, $mineQuote->id]))->assertForbidden();
        $this->actingAs($this->staff('transport_operator'))->get(route('portal.orders.show', $mine))->assertOk()->assertDontSee(__('portal.quotes.confirm')); // staff may look, only clients confirm here
        $this->assertSame('quoted', $mineQuote->fresh()->status);
    }

    private function order(Client $client): Order
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        return app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'PQ-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3, 'actual_weight_kg' => 30]],
        ], null, 'manual');
    }

    private function shipment(Order $order, string $no, ?int $fulfilmentId, string $status): Shipment
    {
        return Shipment::query()->create(['shipment_no' => $no, 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'order_id' => $order->id, 'fulfilment_id' => $fulfilmentId, 'shipment_type' => 'outbound', 'status' => $status, 'tailgate_required' => false]);
    }

    private function quote(Shipment $shipment, Carrier $carrier, array $attributes = []): TransportQuote
    {
        return TransportQuote::query()->create($attributes + [
            'shipment_id' => $shipment->id, 'carrier_id' => $carrier->id, 'source' => 'own_fleet', 'service_level' => 'standard',
            'cost_cents' => 5000, 'customer_price_cents' => 7500, 'eta_days' => 2, 'quote_stage' => 'final', 'status' => 'quoted',
            'quoted_at' => now(), 'expires_at' => now()->addDay(), 'raw_response' => ['pricing_mode' => 'fixed', '_quote_request' => ['zone' => 'metro', 'items' => []]],
        ]);
    }
}
