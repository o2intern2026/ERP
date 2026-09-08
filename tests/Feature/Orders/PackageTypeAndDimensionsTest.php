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

    public function test_every_goods_line_row_carries_dimensions_and_weight_including_rows_added_by_the_template(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $portalUser = $this->clientUser($client);

        foreach ([[$user, route('orders.create')], [$portalUser, route('portal.orders.create')]] as [$actor, $url]) {
            $page = $this->actingAs($actor)->get($url)->assertOk();
            foreach (['length_mm', 'width_mm', 'height_mm', 'actual_weight_kg', 'carton_qty', 'package_type'] as $field) {
                $page->assertSee('name="lines[0]['.$field.']"', false)->assertSee('name="lines[__INDEX__]['.$field.']"', false); // first row + JS template row
            }
            foreach (['weight_kg', 'length_mm', 'width_mm', 'height_mm', 'qty', 'package_type'] as $field) {
                $page->assertSee('name="declared_packages[0]['.$field.']"', false)->assertSee('name="declared_packages[__INDEX__]['.$field.']"', false);
            }
            $page->assertSee('id="goods-line-template"', false)->assertSee('id="add-goods-line"', false)->assertSee(__('orders.actions.add_line'))->assertSee(__('orders.lines.hint'));
        }

        // Staff: two rows with dimensions are stored as entered; a failed submit re-renders the typed rows.
        $lines = [
            ['description_cn' => '展示架', 'package_type' => 'carton', 'carton_qty' => 4, 'actual_weight_kg' => 40, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 350, 'unit_qty' => 8, 'cbm' => 0.084],
            ['description_en' => 'Lamps', 'package_type' => 'crate', 'carton_qty' => 1, 'actual_weight_kg' => 9.5, 'length_mm' => 1200, 'width_mm' => 800, 'height_mm' => 900],
        ];
        $this->actingAs($user)->post(route('orders.store'), $this->payload($client, $job, ['lines' => $lines, 'deliver_to_postcode' => '']))->assertSessionHasErrors('deliver_to_postcode');
        $this->actingAs($user)->get(route('orders.create'))->assertOk()->assertSee('name="lines[1][length_mm]" value="1200"', false)->assertSee('name="lines[0][height_mm]" value="350"', false);
        $this->actingAs($user)->post(route('orders.store'), $this->payload($client, $job, ['lines' => $lines]))->assertSessionHasNoErrors();
        $order = Order::query()->with('lines')->latest('id')->firstOrFail();
        $this->assertSame([[600, 400, 350, '40.000'], [1200, 800, 900, '9.500']], $order->lines->map(fn ($l) => [$l->length_mm, $l->width_mm, $l->height_mm, $l->actual_weight_kg])->all());

        // Portal: the same row layout stores the dimensions too.
        $this->actingAs($portalUser)->post(route('portal.orders.store'), [
            'order_type' => 'from_stock', 'external_ref' => 'PORTAL-DIMS', 'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(3)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_cn' => '灯具', 'package_type' => 'carton', 'carton_qty' => 3, 'actual_weight_kg' => 12, 'length_mm' => 500, 'width_mm' => 300, 'height_mm' => 200]],
        ])->assertSessionHasNoErrors();
        $portalOrder = Order::query()->withoutGlobalScopes()->where('external_ref', 'PORTAL-DIMS')->sole();
        $this->assertSame([500, 300, 200], [$portalOrder->lines[0]->length_mm, $portalOrder->lines[0]->width_mm, $portalOrder->lines[0]->height_mm]);
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
