<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Portal\Services\PortalTransportEstimate;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\PortalCollectionFlow;
use Tests\Support\StubCarrierAdapter;
use Tests\TestCase;

/**
 * 客户门户申请上门提货 (lead decision 2026-09-15, CHANGE_REQUESTS #125), portal side: the client ticks 需要你们上门提货 on its 入库清单 with
 * the pickup address / contact / ready date; the preview lists the collection plans with CLIENT prices for the list's rows and the
 * packages derived from them; confirm needs a plan that is still offered and keeps only the customer snapshot. No ASN is created —
 * customer service reviews it in 待建预报.
 */
class PortalCollectionRequestTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, PortalCollectionFlow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindStubCarriers([
            'own_fleet' => ['code' => 'OWN-PCR', 'name' => 'Edward Own Fleet', 'level' => 'standard', 'cost' => 7500],
            'karrio' => ['code' => 'KAR-PCR', 'name' => 'Demo Freight', 'level' => 'express', 'cost' => 8250],
        ]);
    }

    public function test_the_pickup_fields_are_required_in_chinese_only_for_we_collect_and_the_input_is_kept(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client());
        $warehouse = $this->warehouse();

        $form = $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()
            ->assertSee(__('portal.inbound.collection.section'))->assertSee(__('portal.inbound.collection.modes.we_collect'))->assertSee(__('portal.inbound.collection.hint'))
            ->assertSee('name="inbound_transport"', false)->assertSee('id="collection-fields" hidden disabled', false)
            ->assertSee('<input type="hidden" name="warehouse_id" value="'.$warehouse->id.'">', false);
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.collection\./', $form->getContent(), 'no raw lang key');

        // 需要你们上门提货 without the address: Chinese field errors, nothing uploaded, the typed values come back with the fieldset open.
        $this->actingAs($user)->from(route('portal.asns.imports.create'))->post(route('portal.asns.imports.store'), ['manifest' => $this->collectionCsv($this->collectionRows())]
            + $this->collectionFields($warehouse, ['collection' => ['address' => '', 'suburb' => '', 'postcode' => '']]))
            ->assertRedirect(route('portal.asns.imports.create'))
            ->assertSessionHasErrors(['collection.address' => '请填写提货地址。', 'collection.suburb' => '请填写提货城区。', 'collection.postcode' => '请填写提货邮编。']);
        $this->assertSame(0, OrderImport::query()->withoutGlobalScopes()->count());
        $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()
            ->assertSee('请填写提货地址。')->assertSee('value="Factory"', false)->assertSee('value="Dock 3, 8-4"', false)->assertDontSee('id="collection-fields" hidden disabled', false);

        // A past ready date, a five-digit postcode and a state that is not Australian are refused.
        $this->actingAs($user)->from(route('portal.asns.imports.create'))->post(route('portal.asns.imports.store'), ['manifest' => $this->collectionCsv($this->collectionRows())]
            + $this->collectionFields($warehouse, ['collection_ready_date' => today()->subDay()->toDateString(), 'collection' => ['postcode' => '30280', 'state' => 'MARS']]))
            ->assertSessionHasErrors(['collection_ready_date' => '可提货日期不能早于今天。', 'collection.postcode' => '提货邮编必须是 4 位数字。', 'collection.state' => '提货州不在可选范围内。']);
        $this->assertSame(0, OrderImport::query()->withoutGlobalScopes()->count());

        // 我们自己送到仓库: the upload is exactly as before (#123) — stray pickup fields are ignored and nothing about a collection is stored.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), [
            'manifest' => $this->collectionCsv($this->collectionRows()), 'inbound_transport' => 'client_delivers',
            'warehouse_id' => 999999, 'collection' => ['state' => 'MARS', 'postcode' => 'x'], 'collection_ready_date' => today()->subYear()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->withoutGlobalScopes()->sole();
        $this->assertArrayNotHasKey('collection', $import->errors['context']['inbound']);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee(__('portal.inbound.actions.confirm'))->assertDontSee(__('portal.inbound.collection.title'))->assertDontSee('name="collection_choice"', false);
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertSessionHasNoErrors();
        $this->assertSame(['imported', 2], [$import->fresh()->status, Order::query()->withoutGlobalScopes()->count()]);
    }

    public function test_the_preview_shows_the_plans_with_client_prices_and_the_packages_from_the_rows_and_never_a_cost(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client(['default_markup_percent' => 20])); // Demo Freight: 8250 cost × 1.2 = $99.00; own fleet fixed $75.00
        $warehouse = $this->warehouse();
        $rows = [...$this->collectionRows(), 'MK-C,样品,Samples,纸箱,2,10,10,,,,Shop C,03 9999 0001,5 Low St,Richmond,VIC,3121,,'];
        $import = $this->uploadCollection($user, $warehouse, $rows);

        $this->assertEquals([
            'warehouse_id' => $warehouse->id,
            'address' => ['name' => 'Factory', 'phone' => '0400 000 009', 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'business'],
            'ready_date' => today()->addDays(2)->toDateString(),
            'notes' => 'Dock 3, 8-4',
        ], $import->errors['context']['inbound']['collection']);

        $page = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk();
        foreach ([__('portal.inbound.collection.title'), '9 Supplier Rd, Laverton VIC 3028', 'Factory · 0400 000 009', today()->addDays(2)->toDateString(), 'MEL · MEL DC', 'Dock 3, 8-4',
            __('portal.inbound.collection.packages'), '600×400×400', '1200×1000×1400', '8.5', __('portal.inbound.collection.unpriced', ['rows' => '4']),
            __('portal.inbound.collection.plans_title'), 'Edward Own Fleet', 'Demo Freight', '$75.00', '$99.00', __('orders.estimate.freight_flags.recommended')] as $text) {
            $page->assertSee($text);
        }
        $page->assertSee('name="collection_choice"', false)->assertSee('value="'.$this->planKey('own_fleet', 'standard').'"', false)->assertSee('value="'.$this->planKey('karrio', 'express').'"', false);

        // Client prices only: the carrier cost (8250 → $82.50) and the words cost / margin / 成本 never reach the page; no raw lang key.
        $html = $page->getContent();
        $page->assertDontSee('8250')->assertDontSee('82.50')->assertDontSee('cost_cents')->assertDontSee('markup');
        $visible = preg_replace(['/<script.*?<\/script>/s', '/<style.*?<\/style>/s', '/ style="[^"]*"/'], '', $html);
        $this->assertDoesNotMatchRegularExpression('/\b(cost|margin)\b/i', $visible);
        $this->assertStringNotContainsString('成本', $visible);
        $this->assertDoesNotMatchRegularExpression('/portal\.inbound\.|portal\.quotes\.|orders\.estimate\.|transport\.sources\./', $html);

        // What the carriers were asked: pickup → our warehouse, the priceable rows as parcels (MK-C has no dimensions), pickup-side tailgate for the 300 kg pallet.
        $request = collect(StubCarrierAdapter::$requests)->where('description', 'estimate')->last();
        $this->assertSame(['9 Supplier Rd', '3028', 'MEL DC', '3175', '3028', true, false, today()->addDays(2)->toDateString(), 0], [
            $request['sender']['address'], $request['sender']['postcode'], $request['receiver']['name'], $request['receiver']['postcode'], $request['zone'], $request['tailgate_pickup'], $request['tailgate_delivery'], $request['requested_date'], $request['declared_value_cents'],
        ]);
        $this->assertSame([['蓝牙音箱 / Bluetooth speaker', 10, 8.5], ['水杯 / Mugs', 1, 300.0]], array_map(fn (array $i) => [$i['description'], $i['qty'], $i['weight_kg']], $request['items']));
    }

    public function test_confirm_needs_a_plan_that_is_still_offered_and_keeps_only_the_customer_snapshot(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client(['default_markup_percent' => 20]));
        $stranger = $this->clientUser($this->client());
        $warehouse = $this->warehouse();
        $import = $this->uploadCollection($user, $warehouse);
        $karrio = $this->planKey('karrio', 'express');

        // Plans exist, no choice → Chinese error, nothing imported.
        $this->confirmCollection($user, $import, null)->assertRedirect(route('portal.asns.imports.show', $import))
            ->assertSessionHasErrors(['collection_choice' => __('portal.inbound.collection.errors.choice_required')]);
        // A forged (or no longer offered) key → 提货方案或价格已更新，请重新选择; still nothing imported.
        $this->confirmCollection($user, $import, 'own_fleet|standard|999999')->assertRedirect(route('portal.asns.imports.show', $import))
            ->assertSessionHasErrors(['collection_choice' => __('portal.inbound.collection.errors.choice_changed')]);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.collection.errors.choice_changed'));
        $this->assertSame(['pending', 0], [$import->fresh()->status, Order::query()->withoutGlobalScopes()->count()]);
        $this->assertArrayNotHasKey('preference', $import->fresh()->errors['context']['inbound']['collection']);

        // Another client never reaches the import.
        $this->actingAs($stranger)->get(route('portal.asns.imports.show', $import))->assertNotFound();
        $this->actingAs($stranger)->post(route('portal.asns.imports.confirm', $import), ['collection_choice' => $karrio])->assertNotFound();

        // A valid choice: the orders are created; only the key is trusted — prices / costs posted alongside are ignored.
        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import), ['collection_choice' => $karrio, 'customer_price_cents' => 1, 'cost_cents' => 1, 'preference' => ['cost_cents' => 1]])
            ->assertRedirect(route('portal.asns.imports.show', $import))->assertSessionHasNoErrors();
        $import->refresh();
        $this->assertSame(['imported', 2], [$import->status, Order::query()->withoutGlobalScopes()->count()]);
        $collection = $import->errors['context']['inbound']['collection'];
        $preference = $collection['preference'];
        $this->assertEqualsCanonicalizing([...PortalTransportEstimate::SNAPSHOT, 'chosen_at', 'chosen_by'], array_keys($preference));
        $this->assertSame(AsnService::PREFERENCE_KEYS, [...PortalTransportEstimate::SNAPSHOT, 'chosen_at', 'chosen_by'], 'Warehouse keeps exactly the portal snapshot');
        $this->assertSame(['karrio', 'express', 9900, 'Demo Freight', $user->id], [$preference['source'], $preference['service_level'], $preference['customer_price_cents'], $preference['carrier_name'], $preference['chosen_by']]);
        $this->assertEquals([['row' => 2, 'package_type' => 'carton', 'qty' => 10, 'weight_kg' => 8.5, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400],
            ['row' => 3, 'package_type' => 'pallet', 'qty' => 1, 'weight_kg' => 300, 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 1400]], $collection['packages']);

        // After confirm: the chosen plan with the client price; the list flags the request; still no ASN (customer service builds it).
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee(__('portal.inbound.collection.chosen'))->assertSee('Demo Freight')->assertSee('$99.00')->assertDontSee('name="collection_choice"', false)->assertSee(__('portal.inbound.after_confirm'));
        $this->actingAs($user)->get(route('portal.asns.imports.index'))->assertOk()->assertSee(__('portal.inbound.collection.badge'));
        $this->actingAs($stranger)->get(route('portal.asns.imports.show', $import))->assertNotFound();
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->count());
    }

    public function test_without_a_priced_plan_the_client_still_confirms_and_the_page_says_customer_service_confirms_the_freight(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client());
        $warehouse = $this->warehouse();
        $import = $this->uploadCollection($user, $warehouse, ['MK-C,样品,Samples,纸箱,2,10,10,,,,Shop C,03 9999 0001,5 Low St,Richmond,VIC,3121,,']);

        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee(__('portal.inbound.collection.no_plan.no_items'))->assertSee(__('portal.inbound.collection.no_plan_hint'))->assertDontSee('name="collection_choice"', false);
        $this->confirmCollection($user, $import, null)->assertSessionHasNoErrors()->assertRedirect(route('portal.asns.imports.show', $import));
        $import->refresh();
        $this->assertSame('imported', $import->status);
        $this->assertNull($import->errors['context']['inbound']['collection']['preference']);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee(__('portal.inbound.collection.pending_freight'));
    }
}
