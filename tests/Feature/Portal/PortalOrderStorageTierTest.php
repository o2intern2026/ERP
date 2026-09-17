<?php

namespace Tests\Feature\Portal;

use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Services\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #129: the client chooses the storage TIER (标准 / 底层) per goods line when placing an order in the portal — a tier,
 * never a bin. The form shows the select per row and the client's OWN surcharge percent per warehouse (customer price only); the chosen
 * tier is stored with source `client`, shown on the portal order page and travels 待建预报 → ASN line → received stock unit (#126 chain).
 */
class PortalOrderStorageTierTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_the_create_form_shows_the_tier_select_per_row_and_the_clients_own_percent_hint(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $this->warehouse();

        $page = $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()
            ->assertSee('name="lines[0][storage_tier]"', false)
            ->assertSee('<th>'.__('orders.lines.storage_tier').'</th>', false)
            ->assertSee(__('orders.lines.storage_tiers.bottom'))
            ->assertSee(__('orders.lines.storage_tier_hint'))
            ->assertSee(__('orders.lines.storage_tier_surcharge', ['percent' => '10%'])) // the bound standard card's 10 % — the client's own price
            ->assertDontSee('orders.lines.')->assertDontSee('portal.fields.')
            ->assertDontSee('cost')->assertDontSee('margin')->assertDontSee('WH-STORAGE-TIER')
            ->getContent();
        $this->assertMatchesRegularExpression('/name="lines\[0\]\[storage_tier\]"[^>]*>\s*<option value="standard" selected>/', $page, 'the first row defaults to 标准');
        $this->assertStringContainsString('name="lines[__INDEX__][storage_tier]"', $page, 'the add-line template carries the select too');

        // Finance prices SYD at 20 %: the hint lists the percent per warehouse.
        $item = RateItem::query()->where('rate_card_id', RateCard::query()->where('is_standard', true)->value('id'))->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-TIER-PLT-WK'))->sole();
        RateItem::query()->create(['rate_card_id' => $item->rate_card_id, 'charge_code_id' => $item->charge_code_id, 'pricing_mode' => 'percent', 'markup_percent' => 20, 'warehouse_id' => $this->warehouse('SYD')->id, 'threshold_json' => ['min_percent' => 10, 'max_percent' => 20]]);
        $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()->assertSee(__('orders.lines.storage_tier_surcharge', ['percent' => 'MEL 10% · SYD 20%']));

        // A client whose card does not price the tier still gets the select and the explanation, but no percent line.
        $bare = $this->client();
        $bare->update(['standard_rate_card_id' => null]);
        $this->actingAs($this->clientUser($bare))->get(route('portal.orders.create'))->assertOk()
            ->assertSee('name="lines[0][storage_tier]"', false)->assertSee(__('orders.lines.storage_tier_hint'))
            ->assertDontSee('比标准仓储高');
    }

    public function test_an_invalid_tier_is_a_chinese_row_error_and_a_spare_row_with_only_a_tier_is_pruned(): void
    {
        $user = $this->clientUser($this->client());
        $this->warehouse();

        $this->actingAs($user)->post(route('portal.orders.store'), $this->form(['lines' => [array_replace($this->line('bottom'), ['storage_tier' => 'top'])]]))
            ->assertSessionHasErrors(['lines.0.storage_tier' => '第 1 行货物的存储等级只能选“标准”或“底层”。']);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());

        // The form's spare row submits its two selects only: it is not a line (OrderFormRows), so the tier alone never creates one.
        $this->actingAs($user)->post(route('portal.orders.store'), $this->form(['lines' => [$this->line('bottom'), ['package_type' => 'carton', 'storage_tier' => 'bottom']]]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->withoutGlobalScopes()->with('lines')->sole();
        $this->assertCount(1, $order->lines);
        $this->assertSame(['bottom', 'client'], [$order->lines[0]->storage_tier, $order->lines[0]->storage_tier_source]);
    }

    public function test_the_chosen_tier_is_stored_per_line_shown_on_the_order_page_and_reaches_the_asn_line_and_the_received_unit(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $form = $this->form(['lines' => [$this->line('bottom', 'Watches'), $this->line('standard', 'Straps')]]);

        // 获取估价 keeps the choice on the re-rendered form (old()) and prices no storage line at order time.
        $this->actingAs($user)->post(route('portal.orders.preview'), $form)->assertOk()
            ->assertSee('value="bottom" selected', false)->assertSee(__('portal.estimate.preview_total'))->assertDontSee('WH-STORAGE-TIER');

        $this->actingAs($user)->post(route('portal.orders.store'), $form)->assertSessionHasNoErrors()->assertRedirect();
        $order = Order::query()->withoutGlobalScopes()->with('lines')->sole();
        $lines = $order->lines->keyBy('description_en');
        $this->assertSame(['bottom', 'client'], [$lines['Watches']->storage_tier, $lines['Watches']->storage_tier_source]);
        $this->assertSame(['standard', 'client'], [$lines['Straps']->storage_tier, $lines['Straps']->storage_tier_source], 'the 标准 the client left selected is still the client\'s declaration');

        // The portal order page: one 底层 badge, no location code, no price, no raw key.
        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()
            ->assertSee('<th>'.__('portal.fields.storage_tier').'</th>', false)->assertSee(__('portal.stock.storage_tiers.standard'))
            ->assertDontSee('portal.fields.')->assertDontSee('portal.stock.')->assertDontSee('cost')->assertDontSee('MEL-')->getContent();
        $this->assertSame(1, substr_count($page, '<span class="badge" data-tone="warn">'.__('portal.stock.storage_tiers.bottom').'</span>'));

        // 待建预报 → ASN: the tier and its source are copied onto the goods lines; receiving copies the tier onto the pallet.
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->post(route('orders.inbound.store'), ['order_ids' => [$order->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck'])->assertSessionHasNoErrors()->assertRedirect();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $asnLines = AsnLine::query()->where('asn_id', $asn->id)->get()->keyBy('order_line_id');
        $this->assertSame(['bottom', 'client'], [$asnLines[$lines['Watches']->id]->storage_tier, $asnLines[$lines['Watches']->id]->storage_tier_source]);
        $this->assertSame(['standard', 'client'], [$asnLines[$lines['Straps']->id]->storage_tier, $asnLines[$lines['Straps']->id]->storage_tier_source]);

        [$unit] = app(ReceivingService::class)->receiveLine($asnLines[$lines['Watches']->id], ['received_cartons' => 4, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 4]]], $this->location($warehouse, 'receiving'));
        $this->assertSame('bottom', $unit->fresh()->required_storage_tier);
        $this->assertSame(['bottom', 'client'], [OrderLine::query()->withoutGlobalScopes()->findOrFail($lines['Watches']->id)->storage_tier, $lines['Watches']->fresh()->storage_tier_source], 'the order line itself is untouched by the hand-over');
    }

    /** @return array<string, mixed> */
    private function line(string $tier, string $name = 'Bluetooth speakers'): array
    {
        return ['description_en' => $name, 'package_type' => 'carton', 'carton_qty' => 4, 'actual_weight_kg' => 40, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250, 'storage_tier' => $tier];
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return array_replace([
            'order_type' => 'from_stock', 'deliver_to_name' => 'Amazon FBA BWU2', 'deliver_to_phone' => '0400 000 000', 'deliver_to_address' => '1 Warehouse Rd',
            'deliver_to_suburb' => 'Moorebank', 'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2170', 'deliver_to_address_type' => 'business',
            'requested_date' => today()->addDays(5)->toDateString(), 'service_level' => 'standard',
            'lines' => [$this->line('bottom')],
        ], $overrides);
    }
}
