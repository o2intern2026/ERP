<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderApiToken;
use App\Modules\Orders\Services\OrderApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A4b / OMS-1 API path: bearer-token auth, idempotent replay, creation only through OrderCreationService (source api). */
class OrderApiTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        $client = $this->client();
        $issued = app(OrderApiTokenService::class)->issue($client->id, 'test', null);

        $this->postJson(route('orders.api.orders.store'), $this->payload())->assertStatus(401)->assertJson(['error' => 'unauthenticated']);
        $this->withToken('oak_wrong')->postJson(route('orders.api.orders.store'), $this->payload())->assertStatus(401);

        app(OrderApiTokenService::class)->revoke($issued['token']);
        $this->withToken($issued['plain'])->postJson(route('orders.api.orders.store'), $this->payload())->assertStatus(401);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_valid_token_creates_the_order_for_its_client_and_validates_the_body(): void
    {
        $client = $this->client();
        $other = $this->client();
        $issued = app(OrderApiTokenService::class)->issue($client->id, 'client ERP', null);

        $response = $this->withToken($issued['plain'])->postJson(route('orders.api.orders.store'), $this->payload(['client_id' => $other->id]));

        $response->assertCreated()->assertJson(['operational_status' => 'received', 'customer_status' => 'received', 'replayed' => false]);
        $order = Order::query()->with('lines')->sole();
        $this->assertSame($order->order_no, $response->json('order_no'));
        $this->assertSame([$client->id, 'api', 'from_stock', 'PO-API-1', 10], [$order->client_id, $order->source, $order->order_type, $order->external_ref, $order->lines->first()->carton_qty]);
        $this->assertNotNull($order->job_id);
        $this->assertNotNull($issued['token']->fresh()->last_used_at);

        $this->withToken($issued['plain'])->postJson(route('orders.api.orders.store'), ['order_type' => 'from_stock', 'deliver_to_state' => 'XX'])
            ->assertStatus(422)->assertJson(['error' => 'validation_failed'])->assertJsonValidationErrors(['deliver_to_name', 'deliver_to_state', 'lines'], 'errors');
        $this->withToken($issued['plain'])->postJson(route('orders.api.orders.store'), $this->payload())->assertStatus(422)->assertJsonValidationErrors(['external_ref'], 'errors'); // duplicate reference per client
        $this->assertSame(1, Order::query()->count());
    }

    public function test_idempotency_key_replays_the_same_order_instead_of_duplicating_it(): void
    {
        $client = $this->client();
        $issued = app(OrderApiTokenService::class)->issue($client->id, 'client ERP', null);

        $first = $this->withToken($issued['plain'])->withHeader('Idempotency-Key', 'req-001')->postJson(route('orders.api.orders.store'), $this->payload());
        $replay = $this->withToken($issued['plain'])->withHeader('Idempotency-Key', 'req-001')->postJson(route('orders.api.orders.store'), $this->payload(['external_ref' => 'ignored-on-replay']));
        $other = $this->withToken($issued['plain'])->withHeader('Idempotency-Key', 'req-002')->postJson(route('orders.api.orders.store'), $this->payload(['external_ref' => 'PO-API-2']));

        $first->assertCreated()->assertJson(['replayed' => false]);
        $replay->assertOk()->assertJson(['order_no' => $first->json('order_no'), 'replayed' => true]);
        $other->assertCreated();
        $this->assertSame(2, Order::query()->count());
        $this->assertDatabaseHas('order_api_idempotency_keys', ['client_id' => $client->id, 'idempotency_key' => 'req-001', 'order_id' => $first->json('order_id')]);

        // The same key from another client is a different request.
        $otherClient = app(OrderApiTokenService::class)->issue($this->client()->id, 'other', null);
        $this->withToken($otherClient['plain'])->withHeader('Idempotency-Key', 'req-001')->postJson(route('orders.api.orders.store'), $this->payload())->assertCreated();
        $this->assertSame(3, Order::query()->count());
    }

    public function test_admin_issues_and_revokes_tokens_and_sees_the_plain_token_once(): void
    {
        $admin = $this->staff('admin');
        $client = $this->client(['name' => 'API Client']);

        $this->actingAs($this->staff('customer_service'))->get(route('orders.api-tokens.index'))->assertForbidden();
        $response = $this->actingAs($admin)->post(route('orders.api-tokens.store'), ['client_id' => $client->id, 'name' => 'Production']);
        $response->assertRedirect(route('orders.api-tokens.index'));
        $plain = session('plain_token');
        $this->assertStringStartsWith('oak_', $plain);
        $this->assertDatabaseHas('order_api_tokens', ['client_id' => $client->id, 'name' => 'Production', 'token_hash' => OrderApiTokenService::hash($plain), 'created_by' => $admin->id]);

        $this->actingAs($admin)->get(route('orders.api-tokens.index'))->assertOk()->assertSee('API Client')->assertSee('Production')->assertSee(route('orders.api.orders.store'))->assertSee($plain); // flashed once …
        $this->actingAs($admin)->get(route('orders.api-tokens.index'))->assertOk()->assertDontSee($plain); // … and never again (only the hash is stored)
        $this->withToken($plain)->postJson(route('orders.api.orders.store'), $this->payload())->assertCreated();

        $token = OrderApiToken::query()->sole();
        $this->actingAs($admin)->post(route('orders.api-tokens.revoke', $token))->assertRedirect();
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->withToken($plain)->postJson(route('orders.api.orders.store'), $this->payload(['external_ref' => 'PO-API-3']))->assertStatus(401);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'order_type' => 'from_stock', 'external_ref' => 'PO-API-1', 'consignment_mark' => 'API-MARK',
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'requested_date' => today()->addDays(2)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 10, 'actual_weight_kg' => 8.5]],
        ], $overrides);
    }
}
