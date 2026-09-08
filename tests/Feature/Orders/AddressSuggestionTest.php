<?php

namespace Tests\Feature\Orders;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\AddressSuggestionService;
use App\Modules\Orders\Services\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** Tester feedback item 3b: typing a delivery address suggests the client's own address book + past deliver-to addresses (JSON, max 8), never another client's. */
class AddressSuggestionTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_staff_form_suggestions_combine_the_address_book_and_past_orders_of_the_selected_client_only(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $other = $this->client();
        ClientAddress::query()->create(['client_id' => $client->id, 'label' => 'FBA BWU2', 'contact_name' => 'Amazon BWU2 Receiving', 'phone' => '0299999999', 'address' => '1 Distribution Drive', 'suburb' => 'Kemps Creek', 'state' => 'NSW', 'postcode' => '2178', 'address_type' => 'fba', 'usage_count' => 3]);
        $dock = ['deliver_to_name' => 'Dock Manager', 'deliver_to_phone' => '0400111222', 'deliver_to_address' => '5 Harbour Rd', 'deliver_to_suburb' => 'Port Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3207', 'deliver_to_address_type' => 'residential'];
        $this->order($client, $dock);
        $this->order($client, $dock); // same snapshot twice → one suggestion
        $this->order($client, ['deliver_to_name' => 'Amazon BWU2 Receiving', 'deliver_to_address' => '1 Distribution Drive', 'deliver_to_suburb' => 'Kemps Creek', 'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2178', 'deliver_to_address_type' => 'fba']); // already in the book → deduplicated
        $this->order($other, $dock); // another client's history never leaks

        $this->actingAs($user)->get(route('orders.create'))->assertOk()->assertSee(route('orders.addresses.suggest'))->assertSee('class="suggest-wrap"', false)->assertSee(__('orders.addresses.suggest_hint'));

        $book = $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'distri', 'client_id' => $client->id]))->assertOk()->assertJsonCount(1);
        $book->assertJsonPath('0.source', 'book')->assertJsonPath('0.name', 'Amazon BWU2 Receiving')->assertJsonPath('0.phone', '0299999999')->assertJsonPath('0.address', '1 Distribution Drive')
            ->assertJsonPath('0.suburb', 'Kemps Creek')->assertJsonPath('0.state', 'NSW')->assertJsonPath('0.postcode', '2178')->assertJsonPath('0.address_type', 'fba');
        $this->assertSame(['label', 'name', 'phone', 'address', 'suburb', 'state', 'postcode', 'address_type', 'source'], array_keys($book->json('0')));

        $history = $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'harbour', 'client_id' => $client->id]))->assertOk()->assertJsonCount(1);
        $history->assertJsonPath('0.source', 'history')->assertJsonPath('0.name', 'Dock Manager')->assertJsonPath('0.phone', '0400111222')->assertJsonPath('0.suburb', 'Port Melbourne')->assertJsonPath('0.address_type', 'residential')
            ->assertJsonPath('0.label', 'Dock Manager — 5 Harbour Rd, Port Melbourne VIC 3207');
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'port melb', 'client_id' => $client->id]))->assertOk()->assertJsonCount(1); // suburb
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'dock', 'client_id' => $client->id]))->assertOk()->assertJsonCount(1);      // name
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'kemps', 'client_id' => $client->id]))->assertOk()->assertJsonCount(1);     // book + identical history → one entry

        // No history for the text → nothing; too short → nothing; the other client sees only its own order.
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'Collins St', 'client_id' => $client->id]))->assertOk()->assertExactJson([]);
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => '5', 'client_id' => $client->id]))->assertOk()->assertExactJson([]);
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'harbour', 'client_id' => $other->id]))->assertOk()->assertJsonCount(1)->assertJsonPath('0.source', 'history');
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'distri', 'client_id' => $other->id]))->assertOk()->assertExactJson([]);
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'distri']))->assertStatus(422);

        // At most eight, most recently used first.
        for ($i = 1; $i <= 10; $i++) {
            $this->order($client, ['deliver_to_name' => 'Site '.$i, 'deliver_to_address' => 'Unit '.$i.' Bulk St', 'deliver_to_suburb' => 'Laverton', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3028']);
        }
        $this->actingAs($user)->getJson(route('orders.addresses.suggest', ['q' => 'bulk st', 'client_id' => $client->id]))->assertOk()->assertJsonCount(AddressSuggestionService::LIMIT)->assertJsonPath('0.address', 'Unit 10 Bulk St');

        // Only order-entry roles; client users never reach /orders at all.
        $this->actingAs($this->staff('warehouse_operator'))->getJson(route('orders.addresses.suggest', ['q' => 'harbour', 'client_id' => $client->id]))->assertForbidden();
        $this->actingAs($this->clientUser($client))->getJson(route('orders.addresses.suggest', ['q' => 'harbour', 'client_id' => $client->id]))->assertForbidden();
    }

    public function test_portal_suggestions_are_limited_to_the_signed_in_client(): void
    {
        $client = $this->client();
        $other = $this->client();
        $user = $this->clientUser($client);
        ClientAddress::query()->create(['client_id' => $client->id, 'label' => 'Head office', 'contact_name' => 'Reception', 'address' => '200 Collins St', 'suburb' => 'Melbourne', 'state' => 'VIC', 'postcode' => '3000', 'address_type' => 'business']);
        ClientAddress::query()->create(['client_id' => $other->id, 'label' => 'Their office', 'contact_name' => 'Them', 'address' => '300 Collins St', 'suburb' => 'Melbourne', 'state' => 'VIC', 'postcode' => '3000', 'address_type' => 'business']);
        $this->order($client, ['deliver_to_name' => 'Store 7', 'deliver_to_address' => '7 Chapel St', 'deliver_to_suburb' => 'Windsor', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3181']);
        $this->order($other, ['deliver_to_name' => 'Store 9', 'deliver_to_address' => '9 Chapel St', 'deliver_to_suburb' => 'Windsor', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3181']);

        $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()->assertSee(route('portal.addresses.suggest'))->assertSee(__('portal.create.suggest_hint'));

        $this->actingAs($user)->getJson(route('portal.addresses.suggest', ['q' => 'collins']))->assertOk()->assertJsonCount(1)->assertJsonPath('0.address', '200 Collins St')->assertJsonPath('0.source', 'book');
        $this->actingAs($user)->getJson(route('portal.addresses.suggest', ['q' => 'chapel']))->assertOk()->assertJsonCount(1)->assertJsonPath('0.address', '7 Chapel St')->assertJsonPath('0.name', 'Store 7');
        $this->actingAs($user)->getJson(route('portal.addresses.suggest', ['q' => 'windsor', 'client_id' => $other->id]))->assertOk()->assertJsonCount(1)->assertJsonPath('0.address', '7 Chapel St'); // client_id from the request is ignored
        $this->actingAs($user)->getJson(route('portal.addresses.suggest', ['q' => 'nowhere']))->assertOk()->assertExactJson([]);
        $this->actingAs($this->staff('customer_service'))->getJson(route('portal.addresses.suggest', ['q' => 'chapel']))->assertForbidden();
    }

    private function order(Client $client, array $overrides): Order
    {
        return app(OrderCreationService::class)->create(array_replace([
            'client_id' => $client->id, 'order_type' => 'from_stock', 'external_ref' => 'REF-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_phone' => null, 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 1]],
        ], $overrides), null, 'manual');
    }
}
