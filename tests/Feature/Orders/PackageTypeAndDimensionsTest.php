<?php

namespace Tests\Feature\Orders;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\OrderEnums;
use App\Modules\Orders\Services\OrderCreationService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** Tester feedback items 5 (package type dropdown) and 7 (长宽高 on every goods line) on the staff and portal order forms. */
class PackageTypeAndDimensionsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_package_type_is_a_dropdown_and_only_listed_values_are_accepted_on_the_staff_form(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        $page = $this->actingAs($user)->get(route('orders.create'))->assertOk();
        $page->assertSee('<select name="lines[0][package_type]"', false)->assertSee(__('orders.package_types.carton'))->assertSee(__('orders.package_types.skid'))
            ->assertSee('<select name="declared_packages[0][package_type]"', false);
        $this->assertSame(['carton', 'satchel', 'pallet', 'crate', 'tube', 'flat_pack', 'skid'], OrderEnums::PACKAGE_TYPES);

        $this->actingAs($user)->post(route('orders.store'), $this->payload($client, $job, ['lines' => [['package_type' => 'box']]]))->assertSessionHasErrors('lines.0.package_type');
        $this->actingAs($user)->post(route('orders.store'), $this->payload($client, $job, ['lines' => [['package_type' => 'flat_pack']]]))->assertSessionHasNoErrors();
        $this->assertSame('flat_pack', Order::query()->firstOrFail()->lines()->firstOrFail()->package_type);

        // Spare declared-package rows only carry the dropdown default: they are pruned, the filled row is kept, a bad type is refused.
        $pure = fn (array $packages) => array_diff_key($this->payload($client, null, [
            'order_type' => 'pickup_deliver', 'external_ref' => 'PURE-'.uniqid(),
            'pickup_name' => 'Factory', 'pickup_address_line' => '9 Supplier Rd', 'pickup_suburb' => 'Laverton', 'pickup_state' => 'VIC', 'pickup_postcode' => '3028',
            'declared_packages' => $packages,
        ]), ['lines' => true]);
        $this->actingAs($user)->post(route('orders.store'), $pure([['package_type' => 'skid', 'qty' => 2, 'weight_kg' => 120], ['package_type' => 'carton'], ['package_type' => 'carton']]))->assertSessionHasNoErrors();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame([['skid', 2]], $order->declaredPackages->map(fn ($p) => [$p->package_type, $p->qty])->all());
        $this->actingAs($user)->post(route('orders.store'), $pure([['package_type' => 'envelope', 'qty' => 1]]))->assertSessionHasErrors('declared_packages.0.package_type');
        $this->actingAs($user)->post(route('orders.store'), $pure([['package_type' => 'carton'], ['package_type' => 'carton']]))->assertSessionHasErrors('declared_packages'); // nothing declared at all
    }

    public function test_portal_form_uses_the_same_dropdown_and_legacy_values_still_display_on_both_order_pages(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $staff = $this->staff('customer_service');

        $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()
            ->assertSee('<select name="lines[0][package_type]"', false)->assertSee(__('orders.package_types.satchel'))->assertSee(__('orders.package_types.tube'));

        $portalPayload = fn (array $line) => [
            'order_type' => 'from_stock', 'external_ref' => 'PORTAL-'.uniqid(), 'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(3)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_cn' => '展示架', 'carton_qty' => 2] + $line],
        ];
        $this->actingAs($user)->post(route('portal.orders.store'), $portalPayload(['package_type' => 'box']))->assertSessionHasErrors('lines.0.package_type');
        $this->actingAs($user)->post(route('portal.orders.store'), $portalPayload(['package_type' => 'crate']))->assertSessionHasNoErrors();
        $this->assertSame('crate', Order::query()->withoutGlobalScopes()->firstOrFail()->lines()->firstOrFail()->package_type);

        // Rows that arrived through the API / Excel / ASN paths may carry other values: they render as-is instead of breaking the page.
        $legacy = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'order_type' => 'from_stock', 'external_ref' => 'LEGACY-1', 'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Old goods', 'package_type' => 'box', 'carton_qty' => 1], ['description_en' => 'Pallet goods', 'package_type' => 'pallet', 'carton_qty' => 1]],
        ], null, 'api');
        $this->actingAs($staff)->get(route('orders.show', $legacy))->assertOk()->assertSee('<td>box</td>', false)->assertSee(__('orders.package_types.pallet'));
        $this->actingAs($user)->get(route('portal.orders.show', $legacy))->assertOk()->assertSee('<td>box</td>', false)->assertSee(__('orders.package_types.pallet'));
        $this->assertSame('box', OrderEnums::packageTypeLabel('box'));
        $this->assertSame(__('orders.package_types.carton'), OrderEnums::packageTypeLabel('carton'));
    }

    /** @return array<string, mixed> */
    private function payload(Client $client, ?int $jobId, array $overrides = []): array
    {
        return array_replace_recursive([
            'client_id' => $client->id, 'job_id' => $jobId, 'order_type' => 'from_stock', 'external_ref' => 'REF-'.uniqid(),
            'deliver_to_name' => 'Receiver Pty Ltd', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Dandenong', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3175',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(2)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Widgets', 'package_type' => 'carton', 'carton_qty' => 1, 'actual_weight_kg' => 10]],
        ], $overrides);
    }
}
