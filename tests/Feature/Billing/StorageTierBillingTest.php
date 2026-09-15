<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Seeders\BillingSeeder;
use App\Modules\Billing\Services\RateCardService;
use App\Modules\Billing\Services\StorageBillingService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockSnapshot;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\SnapshotService;
use App\Modules\Warehouse\Services\StockLedger;
use App\Support\Contracts\RateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #126 billing (lead answers 2, 3, 6, 7, 8): WH-STORAGE-TIER-PLT-WK = a percent of the pallet's base weekly storage
 * charge, only for a good pallet whose LAST snapshot of the week is in a bottom-level storage location AND declared bottom. A warehouse
 * row beats the all-warehouse row; a missing or POA base never yields a priced $0 surcharge; re-runs add nothing.
 */
class StorageTierBillingTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const WEEK_DAY = '2026-09-08'; // ISO week 2026-W37 (Mon 7 – Sun 13 Sep)

    private function bottom(Warehouse $warehouse, string $bin = '01'): Location
    {
        return Location::query()->firstOrCreate(
            ['warehouse_id' => $warehouse->id, 'full_code' => "{$warehouse->code}-B-01-{$bin}"],
            ['zone' => 'B', 'aisle' => '01', 'bin' => $bin, 'type' => 'storage', 'storage_tier' => 'bottom', 'rack_level' => 1, 'active' => true],
        );
    }

    /** One received and put-away unit of a goods line declared $tier. */
    private function stored(Client $client, Warehouse $warehouse, string $tier, Location $location, array $unit = []): StockUnit
    {
        $unit += ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 1200, 'weight_kg' => 300, 'pallet_source' => 'client_own'];
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => $unit['carton_qty'], 'storage_tier' => $tier]]);
        [$created] = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $unit['carton_qty'], 'units' => [$unit]], $this->location($warehouse, 'receiving'));
        app(PutawayService::class)->putaway($created, $location, 'test');

        return $created->fresh();
    }

    private function bill(): void
    {
        app(SnapshotService::class)->take(Carbon::parse(self::WEEK_DAY));
        app(StorageBillingService::class)->billWeek(Carbon::parse(self::WEEK_DAY));
    }

    private function tierCharge(StockUnit $unit): ?Charge
    {
        return Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-TIER-PLT-WK'))->where('source_type', 'snapshot')->where('source_id', $unit->id)->first();
    }

    private function tierItem(): RateItem
    {
        return RateItem::query()->where('rate_card_id', RateCard::query()->where('is_standard', true)->value('id'))->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-TIER-PLT-WK'))->sole();
    }

    public function test_a_declared_bottom_pallet_in_a_bottom_location_pays_ten_percent_of_its_base_even_when_partly_picked_and_a_rerun_adds_nothing(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $pallet = $this->stored($client, $warehouse, 'bottom', $this->bottom($warehouse));
        $partly = $this->stored($client, $warehouse, 'bottom', $this->bottom($warehouse, '02'));
        app(StockLedger::class)->record($partly, 'pick', -15, ['source_type' => 'task', 'source_id' => 1]); // 5 of 20 cartons left

        $this->bill();
        app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-13')); // re-run: idempotent

        foreach ([$pallet, $partly] as $unit) {
            $surcharge = $this->tierCharge($unit);
            $this->assertNotNull($surcharge);
            $this->assertSame([45, 'pending', 1.0, '2026-09-13', "unit:{$unit->id}:week:2026-W37", 1], [(int) $surcharge->amount_cents, $surcharge->status, (float) $surcharge->qty, $surcharge->charge_date->toDateString(), $surcharge->source_activity_id, (int) $surcharge->activity_version]);
            $this->assertSame(450, $surcharge->calculation_snapshot_json['context']['base_cents']);
            $this->assertSame((int) $warehouse->id, $surcharge->calculation_snapshot_json['context']['warehouse_id']);
        }
        $this->assertSame(2, Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-TIER-PLT-WK'))->count());
        $this->assertSame(2, Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->count());
        $snapshot = StockSnapshot::query()->where('stock_unit_id', $pallet->id)->sole();
        $this->assertSame(['bottom', 'bottom'], [$snapshot->location_storage_tier, $snapshot->required_storage_tier]);
    }

    public function test_a_sydney_warehouse_row_beats_the_all_warehouse_row_and_matchitem_respects_the_warehouse(): void
    {
        $client = $this->client();
        $mel = $this->warehouse();
        $syd = $this->warehouse('SYD');
        $all = $this->tierItem();
        $sydRow = RateItem::query()->create(['rate_card_id' => $all->rate_card_id, 'charge_code_id' => $all->charge_code_id, 'pricing_mode' => 'percent', 'markup_percent' => 20, 'warehouse_id' => $syd->id, 'threshold_json' => ['min_percent' => 10, 'max_percent' => 20]]);

        $melPallet = $this->stored($client, $mel, 'bottom', $this->bottom($mel));
        $sydPallet = $this->stored($client, $syd, 'bottom', $this->bottom($syd));
        $this->bill();

        $this->assertSame(45, (int) $this->tierCharge($melPallet)->amount_cents);
        $this->assertSame(90, (int) $this->tierCharge($sydPallet)->amount_cents);
        $this->assertSame($sydRow->id, $this->tierCharge($sydPallet)->rate_item_id);
        $this->assertSame($all->id, $this->tierCharge($melPallet)->rate_item_id);

        $rates = app(RateService::class);
        $this->assertSame($sydRow->id, $rates->price($client->id, 'WH-STORAGE-TIER-PLT-WK', 1, ['warehouse_id' => $syd->id, 'base_cents' => 1000])['rate_item_id']);
        $this->assertSame($all->id, $rates->price($client->id, 'WH-STORAGE-TIER-PLT-WK', 1, ['warehouse_id' => $mel->id, 'base_cents' => 1000])['rate_item_id']);
        $noWarehouse = $rates->price($client->id, 'WH-STORAGE-TIER-PLT-WK', 1, ['base_cents' => 1000]);
        $this->assertSame([$all->id, 100], [$noWarehouse['rate_item_id'], $noWarehouse['amount_cents']], 'no warehouse in the context: only the all-warehouse row matches (review 2026-09-15)');
        // A warehouse-only base price is possible too: a SYD storage row is used for SYD, MEL keeps the Edward row.
        $plt = RateItem::query()->where('rate_card_id', $all->rate_card_id)->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->sole();
        $plt->replicate()->fill(['warehouse_id' => $syd->id, 'rate_cents' => 600])->save();
        $this->assertSame(600, $rates->price($client->id, 'WH-STORAGE-PLT-WK', 1, ['pallet_class' => 'standard', 'warehouse_id' => $syd->id])['amount_cents']);
        $this->assertSame(450, $rates->price($client->id, 'WH-STORAGE-PLT-WK', 1, ['pallet_class' => 'standard', 'warehouse_id' => $mel->id])['amount_cents']);
    }

    /**
     * Review 2026-09-15 (BILL-1 / FLOW-1): a MEL / SYD row never reaches a lookup that does not name its warehouse. thresholds() has no
     * warehouse, so a SYD storage row with its threshold JSON left blank must not hide the all-warehouse pallet bands (receiving would
     * class a standard pallet oversize); and every weekly line — rental, pickface, carton — prices each warehouse with its own row.
     */
    public function test_warehouse_rows_never_shadow_thresholds_and_price_rental_pickface_and_cartons_per_warehouse(): void
    {
        $client = $this->client();
        $mel = $this->warehouse();
        $syd = $this->warehouse('SYD');
        $cardId = $this->tierItem()->rate_card_id;
        $codeId = fn (string $code) => ChargeCode::query()->where('code', $code)->value('id');
        $row = fn (string $code) => RateItem::query()->where('rate_card_id', $cardId)->whereNull('warehouse_id')->where('charge_code_id', $codeId($code))->sole();

        $row('WH-STORAGE-PLT-WK')->replicate()->fill(['warehouse_id' => $syd->id, 'rate_cents' => 600, 'threshold_json' => null])->save();
        $row('WH-PALLET-RENT-PLAIN-WK')->replicate()->fill(['warehouse_id' => $syd->id, 'rate_cents' => 90])->save();
        $row('WH-STORAGE-PICKFACE-WK')->replicate()->fill(['warehouse_id' => $syd->id, 'rate_cents' => 900, 'threshold_json' => null])->save();
        $row('WH-PALLET-RENT-PLAIN-WK')->replicate()->fill(['charge_code_id' => $codeId('WH-STORAGE-CTN-WK'), 'warehouse_id' => $syd->id, 'rate_cents' => 30])->save(); // SYD bills cartons, MEL has no carton rate

        $rates = app(RateService::class);
        $this->assertEquals($row('WH-STORAGE-PLT-WK')->threshold_json, $rates->thresholds($client->id, 'WH-STORAGE-PLT-WK'));
        $this->assertSame('standard', $rates->suggestPalletClass($client->id, 1200, 1000, 1200, 300));
        $this->assertSame(450, $rates->price($client->id, 'WH-STORAGE-PLT-WK', 1, ['pallet_class' => 'standard'])['amount_cents']);

        $melPallet = $this->stored($client, $mel, 'standard', $this->location($mel, 'storage'), ['pallet_source' => 'warehouse_plain']);
        $sydPallet = $this->stored($client, $syd, 'standard', $this->location($syd, 'storage'), ['pallet_source' => 'warehouse_plain']);
        $melPick = $this->stored($client, $mel, 'standard', $this->location($mel, 'pickface'));
        $sydPick = $this->stored($client, $syd, 'standard', $this->location($syd, 'pickface'));
        $melCarton = $this->stored($client, $mel, 'standard', $this->location($mel, 'storage'), ['unit_type' => 'carton', 'carton_qty' => 6]);
        $sydCarton = $this->stored($client, $syd, 'standard', $this->location($syd, 'storage'), ['unit_type' => 'carton', 'carton_qty' => 6]);
        $this->assertSame(['standard', 'standard'], [$melPallet->pallet_class, $sydPallet->pallet_class], 'receiving classes both pallets with the all-warehouse bands');
        $this->bill();

        $amount = fn (string $code, StockUnit $unit) => Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->where('code', $code))->where('job_id', $unit->job_id)->value('amount_cents');
        $this->assertSame([450, 600], [(int) $amount('WH-STORAGE-PLT-WK', $melPallet), (int) $amount('WH-STORAGE-PLT-WK', $sydPallet)]);
        $this->assertSame([70, 90], [(int) $amount('WH-PALLET-RENT-PLAIN-WK', $melPallet), (int) $amount('WH-PALLET-RENT-PLAIN-WK', $sydPallet)]);
        $this->assertSame([650, 900], [(int) $amount('WH-STORAGE-PICKFACE-WK', $melPick), (int) $amount('WH-STORAGE-PICKFACE-WK', $sydPick)]);
        $this->assertSame(180, (int) $amount('WH-STORAGE-CTN-WK', $sydCarton));
        $this->assertNull($amount('WH-STORAGE-CTN-WK', $melCarton), 'the SYD carton row never prices a MEL carton');
    }

    public function test_no_surcharge_without_both_tiers_for_cartons_or_for_snapshot_rows_written_before_the_tier_columns(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $declaredInStandard = $this->stored($client, $warehouse, 'bottom', $this->location($warehouse, 'storage'));
        $standardInBottom = $this->stored($client, $warehouse, 'standard', $this->bottom($warehouse));
        $carton = $this->stored($client, $warehouse, 'bottom', $this->bottom($warehouse, '02'), ['unit_type' => 'carton', 'carton_qty' => 6]);
        $legacy = $this->stored($client, $warehouse, 'bottom', $this->bottom($warehouse, '03'));
        $moved = $this->stored($client, $warehouse, 'bottom', $this->bottom($warehouse, '04'));

        // Last snapshot of the week decides (lead answer 8): bottom on Tuesday, moved to a standard location before Thursday's snapshot.
        app(SnapshotService::class)->take(Carbon::parse('2026-09-08'));
        $moved->update(['location_id' => $this->location($warehouse, 'storage')->id]);
        app(SnapshotService::class)->take(Carbon::parse('2026-09-10'));
        StockSnapshot::query()->where('stock_unit_id', $legacy->id)->update(['location_storage_tier' => null, 'required_storage_tier' => null]);
        app(StorageBillingService::class)->billWeek(Carbon::parse(self::WEEK_DAY));

        foreach ([$declaredInStandard, $standardInBottom, $carton, $legacy, $moved] as $unit) {
            $this->assertNull($this->tierCharge($unit), "unit {$unit->label_code} must not pay the bottom surcharge");
        }
        $this->assertSame(4, Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->count(), 'the base storage is still charged');
    }

    public function test_a_poa_or_missing_base_makes_the_surcharge_need_review_never_a_priced_zero(): void
    {
        $warehouse = $this->warehouse();
        $client = $this->client();
        $overweight = $this->stored($client, $warehouse, 'bottom', $this->bottom($warehouse), ['weight_kg' => 900]);
        $this->assertSame('overweight', $overweight->pallet_class);

        // A client without the standard card whose own card prices only the surcharge: the base storage rate is missing.
        $bare = $this->client(['name' => 'No base rate']);
        $bare->update(['standard_rate_card_id' => null]);
        $card = RateCard::query()->create(['client_id' => $bare->id, 'name' => 'tier only', 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'active', 'is_standard' => false]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => ChargeCode::query()->where('code', 'WH-STORAGE-TIER-PLT-WK')->value('id'), 'pricing_mode' => 'percent', 'markup_percent' => 15]);
        $noBase = $this->stored($bare, $warehouse, 'bottom', $this->bottom($warehouse, '02'));

        $this->bill();

        $poaBase = Charge::query()->withoutGlobalScopes()->where('source_id', $overweight->id)->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-OVERWEIGHT-WK'))->sole();
        $this->assertSame('needs_review', $poaBase->status);
        foreach ([$overweight, $noBase] as $unit) {
            $surcharge = $this->tierCharge($unit);
            $this->assertNotNull($surcharge, 'the surcharge is visible for review');
            $this->assertSame('needs_review', $surcharge->status);
            $this->assertArrayNotHasKey('base_cents', $surcharge->calculation_snapshot_json['context']);
            $this->assertSame('no_base_cents', $surcharge->calculation_snapshot_json['reason']);
        }
        $this->assertSame(0, Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-TIER-PLT-WK'))->where('status', 'pending')->where('amount_cents', 0)->count());
        $this->assertNull(Charge::query()->withoutGlobalScopes()->where('source_id', $noBase->id)->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->first(), 'the base itself is a Missing Rate exception, not a charge');
    }

    public function test_the_rate_card_form_keeps_the_percent_inside_the_band_and_the_seeder_is_idempotent(): void
    {
        $finance = $this->staff('finance');
        $client = $this->client();
        $syd = $this->warehouse('SYD');
        $card = app(RateCardService::class)->createClientCard($client, $finance, today(), 'Client card');
        $codeId = ChargeCode::query()->where('code', 'WH-STORAGE-TIER-PLT-WK')->value('id');
        $band = __('billing.rate_cards.errors.tier_band', ['min' => '10', 'max' => '20']);
        $this->assertStringContainsString('10% – 20%', $band);

        $this->actingAs($finance)->post(route('billing.rate_cards.items.store', $card), ['charge_code_id' => $codeId, 'pricing_mode' => 'percent', 'markup_percent' => 25, 'warehouse_id' => $syd->id])
            ->assertSessionHasErrors(['markup_percent' => $band]);
        $this->actingAs($finance)->post(route('billing.rate_cards.items.store', $card), ['charge_code_id' => $codeId, 'pricing_mode' => 'fixed', 'rate' => 1])
            ->assertSessionHasErrors(['markup_percent' => __('billing.rate_cards.errors.tier_percent_only')]);
        $this->actingAs($finance)->post(route('billing.rate_cards.items.store', $card), ['charge_code_id' => $codeId, 'pricing_mode' => 'percent', 'markup_percent' => 15, 'warehouse_id' => $syd->id])
            ->assertSessionHasNoErrors();
        $item = RateItem::query()->where('rate_card_id', $card->id)->sole();
        $this->assertSame(['percent', '15.00', $syd->id], [$item->pricing_mode, $item->markup_percent, $item->warehouse_id]);
        // The item's own band wins over the standard card's; editing keeps the warehouse (hidden field).
        $this->actingAs($finance)->post(route('billing.rate_items.update', $item), ['pricing_mode' => 'percent', 'markup_percent' => 25, 'warehouse_id' => $syd->id, 'threshold_json' => '{"min_percent":20,"max_percent":30}'])->assertSessionHasNoErrors();
        $this->assertSame(['25.00', $syd->id], [$item->fresh()->markup_percent, $item->fresh()->warehouse_id]);
        $this->actingAs($finance)->post(route('billing.rate_items.update', $item), ['pricing_mode' => 'percent', 'markup_percent' => 5, 'warehouse_id' => $syd->id])->assertSessionHasErrors(['markup_percent' => $band]);

        $page = $this->actingAs($finance)->get(route('billing.rate_cards.show', $card))->assertOk()->assertSee('SYD')->assertSee(__('billing.rate_cards.all_warehouses'))->assertSee(__('billing.rate_cards.warehouse'));
        $page->assertDontSee('billing.rate_cards.');

        // BillingSeeder is re-run by hand on the server: the surcharge line is added once to the live standard card, never twice.
        $standard = RateCard::query()->where('is_standard', true)->sole();
        $this->seed(BillingSeeder::class);
        $this->seed(BillingSeeder::class);
        $this->assertSame(1, RateItem::query()->where('rate_card_id', $standard->id)->where('charge_code_id', $codeId)->count());
        $this->assertSame(35, RateItem::query()->where('rate_card_id', $standard->id)->count());
        $seeded = $this->tierItem();
        $this->assertSame(['percent', '10.00', null], [$seeded->pricing_mode, $seeded->markup_percent, $seeded->warehouse_id]);
        $this->assertEquals(['min_percent' => 10, 'max_percent' => 20], $seeded->threshold_json); // MySQL JSON reorders keys
        $this->assertSame(['storage', 'pallet_week', 'gst_10', 'Storage – bottom-level location surcharge'], array_values(ChargeCode::query()->whereKey($codeId)->firstOrFail()->only(['category', 'default_uom', 'tax_treatment', 'customer_description'])));
        $this->assertSame(1, ChargeCode::query()->where('code', 'WH-STORAGE-TIER-PLT-WK')->count());
    }
}
