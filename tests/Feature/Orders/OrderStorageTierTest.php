<?php

namespace Tests\Feature\Orders;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #129 on the staff side: the manual order form, the order API and the draft line add / edit carry the same 存储等级
 * field as the portal form. Staff entries are stored with source `staff`, API bodies with source `client`; the staff order page shows
 * the tier badge and who chose it, and the re-rendered staff form prices the hint for the client it came back with.
 */
class OrderStorageTierTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_the_staff_form_stores_a_staff_declared_tier_and_prices_the_hint_for_the_selected_client_on_re_render(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $this->warehouse();

        // A fresh form: the select on the first row and in the template, the explanation, but no percent (no client selected yet).
        $this->actingAs($cs)->get(route('orders.create'))->assertOk()
            ->assertSee('name="lines[0][storage_tier]"', false)->assertSee('name="lines[__INDEX__][storage_tier]"', false)
            ->assertSee('<th>'.__('orders.lines.storage_tier').'</th>', false)->assertSee(__('orders.lines.storage_tier_hint'))
            ->assertSee('colspan="13"', false)
            ->assertDontSee('比标准仓储高')->assertDontSee('orders.lines.');

        // A validation error sends the form back with old(): the chosen tier stays selected and the hint is priced for THAT client.
        $this->actingAs($cs)->post(route('orders.store'), $this->payload($client, ['deliver_to_postcode' => '', 'lines' => [$this->line('bottom')]]))->assertSessionHasErrors('deliver_to_postcode');
        $this->actingAs($cs)->get(route('orders.create'))->assertOk()
            ->assertSee('value="bottom" selected', false)
            ->assertSee(__('orders.lines.storage_tier_surcharge', ['percent' => '10%']));
        $this->actingAs($cs)->post(route('orders.store'), $this->payload($client, ['lines' => [array_replace($this->line('bottom'), ['storage_tier' => 'middle'])]]))
            ->assertSessionHasErrors(['lines.0.storage_tier' => '第 1 行货物的存储等级只能选“标准”或“底层”。']);

        $this->actingAs($cs)->post(route('orders.store'), $this->payload($client, ['lines' => [$this->line('bottom', 'Watches'), $this->line('standard', 'Straps')]]))->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->with('lines')->sole();
        $lines = $order->lines->keyBy('description_en');
        $this->assertSame(['bottom', 'staff'], [$lines['Watches']->storage_tier, $lines['Watches']->storage_tier_source]);
        $this->assertSame(['standard', 'staff'], [$lines['Straps']->storage_tier, $lines['Straps']->storage_tier_source]);

        // The staff order page: the column with badge + source, one warn badge for the bottom line.
        $page = $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()
            ->assertSee('<th>'.__('orders.lines.storage_tier').'</th>', false)
            ->assertSee(__('orders.lines.storage_tier_sources.staff'))
            ->assertSee('<span class="badge" data-tone="muted">'.__('orders.lines.storage_tiers.standard').'</span>', false)
            ->assertDontSee('orders.lines.')->getContent();
        $this->assertSame(1, substr_count($page, '<span class="badge" data-tone="warn">'.__('orders.lines.storage_tiers.bottom').'</span>'));
    }

    public function test_the_api_stores_a_client_declared_tier_and_documents_the_field(): void
    {
        $client = $this->client();
        $issued = app(OrderApiTokenService::class)->issue($client->id, 'client ERP', null);

        $body = $this->apiPayload(['lines' => [
            ['description_en' => 'Watches', 'package_type' => 'carton', 'carton_qty' => 10, 'storage_tier' => 'bottom'],
            ['description_en' => 'Straps', 'package_type' => 'carton', 'carton_qty' => 5], // no field: no tier, no source (standard once on the ASN)
        ]]);
        $this->withToken($issued['plain'])->postJson(route('orders.api.orders.store'), $body)->assertCreated();
        $lines = Order::query()->withoutGlobalScopes()->sole()->lines->keyBy('description_en');
        $this->assertSame(['bottom', 'client'], [$lines['Watches']->storage_tier, $lines['Watches']->storage_tier_source]);
        $this->assertSame([null, null], [$lines['Straps']->storage_tier, $lines['Straps']->storage_tier_source]);

        $this->withToken($issued['plain'])->postJson(route('orders.api.orders.store'), $this->apiPayload(['external_ref' => 'PO-API-2', 'lines' => [['description_en' => 'Watches', 'carton_qty' => 1, 'storage_tier' => 'top']]]))
            ->assertStatus(422)->assertJsonValidationErrors(['lines.0.storage_tier'], 'errors');

        // The token page's usage example names the field.
        $this->actingAs($this->staff('admin'))->get(route('orders.api-tokens.index'))->assertOk()
            ->assertSee('"storage_tier":"standard"', false)->assertSee(__('orders.api.storage_tier_hint'))->assertDontSee('orders.api.');
    }

    public function test_draft_line_add_and_edit_set_the_tier_with_source_staff(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $this->actingAs($cs)->post(route('orders.store'), $this->payload($client, ['lines' => [['description_en' => 'Widgets', 'package_type' => 'carton', 'carton_qty' => 1]]]))->assertSessionHasNoErrors();
        $order = Order::query()->with('lines')->sole();
        $widgets = $order->lines->sole();
        $this->assertSame([null, null], [$widgets->storage_tier, $widgets->storage_tier_source], 'a form without the field stores no tier');

        // The received draft's line forms carry the select; a line without a tier shows 标准 and no source.
        $page = $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()->assertSee('name="storage_tier"', false)->getContent();
        $this->assertSame(2, substr_count($page, 'name="storage_tier"'), 'one select in the edit form of the line and one in the add form');
        $this->assertStringNotContainsString(__('orders.lines.storage_tier_sources.staff'), $page);

        $this->actingAs($cs)->post(route('orders.lines.store', $order), ['description_cn' => '手表', 'package_type' => 'carton', 'carton_qty' => 2, 'storage_tier' => 'bottom'])->assertSessionHasNoErrors();
        $watches = $order->lines()->where('description_cn', '手表')->sole();
        $this->assertSame(['bottom', 'staff'], [$watches->storage_tier, $watches->storage_tier_source]);

        $this->actingAs($cs)->patch(route('orders.lines.update', [$order, $widgets]), ['description_en' => 'Widgets', 'carton_qty' => 1, 'storage_tier' => 'top'])->assertSessionHasErrors(['storage_tier' => '存储等级只能选“标准”或“底层”。']);
        $this->actingAs($cs)->patch(route('orders.lines.update', [$order, $widgets]), ['description_en' => 'Widgets', 'carton_qty' => 1, 'storage_tier' => 'bottom'])->assertSessionHasNoErrors();
        $this->assertSame(['bottom', 'staff'], [$widgets->fresh()->storage_tier, $widgets->fresh()->storage_tier_source]);
        // An edit that does not send the field (an older form) leaves the tier and its source alone.
        $this->actingAs($cs)->patch(route('orders.lines.update', [$order, $widgets]), ['description_en' => 'Widgets renamed', 'carton_qty' => 3])->assertSessionHasNoErrors();
        $this->assertSame(['Widgets renamed', 3, 'bottom', 'staff'], [$widgets->fresh()->description_en, $widgets->fresh()->carton_qty, $widgets->fresh()->storage_tier, $widgets->fresh()->storage_tier_source]);
        // Back to 标准 is a staff declaration too, not "no tier".
        $this->actingAs($cs)->patch(route('orders.lines.update', [$order, $watches]), ['description_cn' => '手表', 'carton_qty' => 2, 'storage_tier' => 'standard'])->assertSessionHasNoErrors();
        $this->assertSame(['standard', 'staff'], [$watches->fresh()->storage_tier, $watches->fresh()->storage_tier_source]);

        $page = $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()->getContent();
        $this->assertSame(1, substr_count($page, '<span class="badge" data-tone="warn">'.__('orders.lines.storage_tiers.bottom').'</span>'));
        $this->assertSame(2, substr_count($page, __('orders.lines.storage_tier_sources.staff')));
    }

    /** @return array<string, mixed> */
    private function line(string $tier, string $name = 'Widgets'): array
    {
        return ['description_en' => $name, 'package_type' => 'carton', 'carton_qty' => 1, 'actual_weight_kg' => 10, 'storage_tier' => $tier];
    }

    /** @return array<string, mixed> */
    private function payload(Client $client, array $overrides = []): array
    {
        return array_replace([
            'client_id' => $client->id, 'job_id' => null, 'order_type' => 'from_stock', 'external_ref' => 'REF-'.uniqid(),
            'deliver_to_name' => 'Receiver Pty Ltd', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Dandenong', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3175',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(2)->toDateString(), 'service_level' => 'standard',
            'lines' => [$this->line('standard')],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function apiPayload(array $overrides = []): array
    {
        return array_replace([
            'order_type' => 'from_stock', 'external_ref' => 'PO-API-1', 'consignment_mark' => 'API-MARK',
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'requested_date' => today()->addDays(2)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 10, 'actual_weight_kg' => 8.5]],
        ], $overrides);
    }
}
