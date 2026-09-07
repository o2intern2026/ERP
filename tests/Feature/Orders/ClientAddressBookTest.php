<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A17: reusable, client-scoped delivery addresses ordered by frequency. */
class ClientAddressBookTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** ERP_PLAN §3.8 #11: a repeated FBA delivery is filled from the book, including its fixed notes. */
    public function test_second_order_to_the_same_fba_address_uses_the_saved_snapshot_and_fixed_notes(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client(['name' => 'Repeat FBA Client']);
        $job = app(JobService::class)->create($client->id, 'loose');

        $this->actingAs($user)->post(route('orders.addresses.store'), [
            'client_id' => $client->id,
            'label' => 'FBA BWU2',
            'contact_name' => 'Amazon BWU2 Receiving',
            'phone' => '0299999999',
            'address' => '1 Distribution Drive',
            'suburb' => 'Kemps Creek',
            'state' => 'NSW',
            'postcode' => '2178',
            'address_type' => 'fba',
            'default_instructions' => '预约后到 3 号卸货门，携带 FBA Shipment ID。',
        ])->assertSessionHasNoErrors()->assertRedirect(route('orders.addresses.index'));

        $address = ClientAddress::query()->firstOrFail();
        $this->actingAs($user)->get(route('orders.create'))
            ->assertOk()
            ->assertSee('FBA BWU2')
            ->assertSee($address->default_instructions);

        foreach (['PO-FIRST', 'PO-SECOND'] as $reference) {
            $this->actingAs($user)->post(route('orders.store'), [
                'client_id' => $client->id,
                'job_id' => $job['job_id'],
                'order_type' => 'from_stock',
                'external_ref' => $reference,
                'client_address_id' => $address->id,
                'requested_date' => '2026-09-30',
                'service_level' => 'standard',
                'lines' => [[
                    'description_cn' => '展示架',
                    'package_type' => 'carton',
                    'carton_qty' => 5,
                ]],
            ])->assertSessionHasNoErrors()->assertRedirect();
        }

        $second = Order::query()->where('external_ref', 'PO-SECOND')->firstOrFail();
        $this->assertSame('Amazon BWU2 Receiving', $second->deliver_to_name);
        $this->assertSame('1 Distribution Drive', $second->deliver_to_address);
        $this->assertSame('Kemps Creek', $second->deliver_to_suburb);
        $this->assertSame('NSW', $second->deliver_to_state);
        $this->assertSame('2178', $second->deliver_to_postcode);
        $this->assertSame('fba', $second->deliver_to_address_type);
        $this->assertSame('预约后到 3 号卸货门，携带 FBA Shipment ID。', $second->delivery_instructions);
        $this->assertSame(2, $address->fresh()->usage_count);
        $this->assertNotNull($address->fresh()->last_used_at);
    }

    public function test_address_book_is_sorted_by_frequency_and_address_snapshots_do_not_change(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');
        $used = ClientAddress::query()->create($this->addressData($client->id, 'Frequently Used FBA', 3));
        ClientAddress::query()->create($this->addressData($client->id, 'Unused Warehouse', 0));

        $this->actingAs($user)->get(route('orders.addresses.index'))
            ->assertOk()
            ->assertSeeInOrder(['Frequently Used FBA', 'Unused Warehouse']);

        $this->actingAs($user)->post(route('orders.store'), [
            'client_id' => $client->id,
            'job_id' => $job['job_id'],
            'order_type' => 'from_stock',
            'external_ref' => 'SNAPSHOT-1',
            'client_address_id' => $used->id,
            'requested_date' => '2026-09-30',
            'service_level' => 'standard',
            'lines' => [[
                'description_en' => 'Display stand',
                'package_type' => 'carton',
                'carton_qty' => 1,
            ]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::query()->where('external_ref', 'SNAPSHOT-1')->firstOrFail();
        $used->update(['address' => '99 Replacement Road', 'default_instructions' => 'New instructions']);

        $this->assertSame('1 Distribution Drive', $order->fresh()->deliver_to_address);
        $this->assertSame('Book delivery before arrival.', $order->fresh()->delivery_instructions);
    }

    /** @return array<string, mixed> */
    private function addressData(int $clientId, string $label, int $usageCount): array
    {
        return [
            'client_id' => $clientId,
            'label' => $label,
            'contact_name' => 'FBA Receiving',
            'phone' => '0299999999',
            'address' => '1 Distribution Drive',
            'suburb' => 'Kemps Creek',
            'state' => 'NSW',
            'postcode' => '2178',
            'address_type' => 'fba',
            'default_instructions' => 'Book delivery before arrival.',
            'usage_count' => $usageCount,
        ];
    }
}
