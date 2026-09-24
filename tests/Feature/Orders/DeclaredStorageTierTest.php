<?php

namespace Tests\Feature\Orders;

use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Models\OrderLine;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #126: the storage tier the client declares (存储等级 column, or pre-filled from the declared unit price when the
 * client's card sets a threshold) travels portal CSV → order_lines → 待建预报 ASN → asn_lines → receiving → stock unit. Staff can change
 * a line's tier (units follow); the portal shows the declared tier only — no location codes, no cost.
 */
class DeclaredStorageTierTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const HEADERS = '唛头,中文品名,英文品名,包装类型,箱数,产品数量,实重(KG),长(CM),宽(CM),高(CM),收件人,电话,地址,城区,州,邮编,FBA参考号,要求送达日,单价,存储等级';

    private function csv(array $rows, string $name = '清单.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xEF\xBB\xBF".implode("\n", [self::HEADERS, ...$rows])."\n");
    }

    private function row(string $mark, string $price, string $tier, string $name = 'Shop'): string
    {
        return "{$mark},手表,Watch,纸箱,10,100,85,60,40,40,{$name},0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,,,{$price},{$tier}";
    }

    public function test_portal_csv_tier_reaches_order_lines_the_asn_line_and_the_received_unit(): void
    {
        Storage::fake('local');
        $client = $this->client(['name' => 'Edward']);
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();

        $this->actingAs($user)->get(route('portal.asns.imports.create'))->assertOk()->assertSee('存储等级')->assertSee(__('portal.inbound.storage_tier_hint'));
        // CHANGE_REQUESTS #146: the template is the client's consolidation list one to one (no 存储等级 column); the column is still read from any sheet that carries it.
        $this->actingAs($user)->get(route('portal.asns.imports.template'))->assertOk()->assertDontSee('存储等级');

        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->csv([
            $this->row('MK-A', '12', '底层', 'Shop A'),
            $this->row('MK-B', '12', '', 'Shop B'),
            $this->row('MK-C', '12', 'standard', 'Shop C'),
        ])])->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->sole();
        $rows = collect($import->errors['groups'])->keyBy('consignment_mark')->map(fn ($g) => $g['rows'][0]);
        $this->assertSame(['bottom', 'client'], [$rows['MK-A']['storage_tier'], $rows['MK-A']['storage_tier_source']]);
        $this->assertSame(['standard', null], [$rows['MK-B']['storage_tier'], $rows['MK-B']['storage_tier_source']], 'an empty cell is standard, not a declaration');
        $this->assertSame(['standard', 'client'], [$rows['MK-C']['storage_tier'], $rows['MK-C']['storage_tier_source']]);

        // The preview shows the column and the client's own surcharge percent (the standard card's 10 %) — never cost.
        $preview = $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee('<th>'.__('portal.inbound.fields.storage_tier').'</th>', false)
            ->assertSee(__('portal.inbound.tier_surcharge', ['percent' => '10%']))
            ->assertDontSee('portal.inbound.')->assertDontSee('cost')->getContent();
        // The per-row 存储等级 cell itself (not the surcharge line): one bottom badge for MK-A, no 按单价预选 badge.
        $this->assertSame(1, substr_count($preview, '<span class="badge" data-tone="warn">'.__('portal.stock.storage_tiers.bottom').'</span>'));
        $this->assertStringNotContainsString('<span class="badge" data-tone="info">'.__('portal.inbound.tier_prefilled').'</span>', $preview);

        $this->actingAs($user)->post(route('portal.asns.imports.confirm', $import))->assertRedirect();
        $lines = OrderLine::query()->with('order')->get()->keyBy(fn ($l) => $l->order->consignment_mark);
        $this->assertSame(['bottom', 'client'], [$lines['MK-A']->storage_tier, $lines['MK-A']->storage_tier_source]);
        $this->assertSame(['standard', null], [$lines['MK-B']->storage_tier, $lines['MK-B']->storage_tier_source]);

        // 待建预报 → ASN: the tier is copied onto the goods lines.
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->post(route('orders.inbound.store'), ['order_ids' => Order::query()->withoutGlobalScopes()->pluck('id')->all(), 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck'])->assertRedirect();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $asnLines = AsnLine::query()->where('asn_id', $asn->id)->get()->keyBy('consignment_mark');
        $this->assertSame(['bottom', 'client'], [$asnLines['MK-A']->storage_tier, $asnLines['MK-A']->storage_tier_source]);
        $this->assertSame('standard', $asnLines['MK-B']->storage_tier);

        // Receiving copies the line's tier onto the stock unit; the staff ASN page shows the source.
        [$unit] = app(ReceivingService::class)->receiveLine($asnLines['MK-A'], ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10]]], $this->location($warehouse, 'receiving'));
        $this->assertSame('bottom', $unit->fresh()->required_storage_tier);
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.line_tier.column'))->assertSee(__('warehouse.line_tier.sources.client'))->assertDontSee('warehouse.line_tier.');

        // The portal stock page shows the declared tier per row — no location code, no price.
        app(PutawayService::class)->putaway($unit->fresh(), Location::query()->create(['warehouse_id' => $warehouse->id, 'full_code' => 'MEL-B-01-01', 'zone' => 'B', 'aisle' => '01', 'bin' => '01', 'type' => 'storage', 'storage_tier' => 'bottom', 'rack_level' => 1, 'active' => true]));
        $this->actingAs($user)->get(route('portal.stock.index'))->assertOk()
            ->assertSee(__('portal.stock.fields.storage_tier'))->assertSee(__('portal.stock.storage_tiers.bottom'))
            ->assertDontSee('MEL-B-01-01')->assertDontSee('MEL-RCV')->assertDontSee('WH-STORAGE')->assertDontSee('portal.stock.');
    }

    public function test_an_unknown_tier_is_a_chinese_row_error_and_the_value_prefill_needs_a_client_threshold_and_loses_to_an_explicit_cell(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $user = $this->clientUser($client);

        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->csv([$this->row('ERR', '12', '顶层')], 'err.csv')])->assertRedirect();
        $import = OrderImport::query()->latest('id')->first();
        $this->assertSame([], $import->errors['groups']);
        $this->assertSame(__('orders.imports.errors.invalid_storage_tier', ['row' => 2, 'field' => '存储等级', 'value' => '顶层']), $import->errors['issues'][0]['message']);
        $this->assertSame('storage_tier', $import->errors['issues'][0]['column']);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee('第 2 行「存储等级」无法识别');

        // No threshold on the card → a $1,500 watch stays standard.
        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->csv([$this->row('VAL-1', '1500', '')], 'a.csv')])->assertRedirect();
        $row = OrderImport::query()->latest('id')->first()->errors['groups'][0]['rows'][0];
        $this->assertSame(['standard', null], [$row['storage_tier'], $row['storage_tier_source']]);

        // The client's card (here the bound standard card) sets tier_value_threshold_cents = $1,000.
        $item = RateItem::query()->where('rate_card_id', RateCard::query()->where('is_standard', true)->value('id'))->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-TIER-PLT-WK'))->sole();
        $item->update(['threshold_json' => $item->threshold_json + ['tier_value_threshold_cents' => 100000]]);
        // Finance adds a SYD row at 20 % without the pre-fill key (review 2026-09-15): thresholds() has no warehouse, so the SYD row must
        // not hide the all-warehouse row's tier_value_threshold_cents — the pre-fill keeps working for every warehouse.
        RateItem::query()->create(['rate_card_id' => $item->rate_card_id, 'charge_code_id' => $item->charge_code_id, 'pricing_mode' => 'percent', 'markup_percent' => 20, 'warehouse_id' => $this->warehouse('SYD')->id, 'threshold_json' => ['min_percent' => 10, 'max_percent' => 20]]);

        $this->actingAs($user)->post(route('portal.asns.imports.store'), ['manifest' => $this->csv([
            $this->row('VAL-2', '1500', '', 'Shop 1'),
            $this->row('VAL-3', '1500', '标准', 'Shop 2'),
            $this->row('VAL-4', '999.99', '', 'Shop 3'),
        ], 'b.csv')])->assertRedirect();
        $import = OrderImport::query()->latest('id')->first();
        $rows = collect($import->errors['groups'])->keyBy('consignment_mark')->map(fn ($g) => $g['rows'][0]);
        $this->assertSame(['bottom', 'value_rule'], [$rows['VAL-2']['storage_tier'], $rows['VAL-2']['storage_tier_source']]);
        $this->assertSame(['standard', 'client'], [$rows['VAL-3']['storage_tier'], $rows['VAL-3']['storage_tier_source']], 'an explicit cell beats the pre-fill');
        $this->assertSame(['standard', null], [$rows['VAL-4']['storage_tier'], $rows['VAL-4']['storage_tier_source']]);
        $this->assertSame([__('orders.imports.warnings.tier_value_prefill', ['row' => 2])], collect($import->errors['warnings'])->where('column', 'storage_tier')->pluck('message')->values()->all());
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()
            ->assertSee('<span class="badge" data-tone="info">'.__('portal.inbound.tier_prefilled').'</span>', false)->assertSee('第 2 行按单价预选底层');

        // Staff see the same import read-only with a 存储等级 column; a staff upload declares with source staff.
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('orders.imports.show', $import))->assertOk()->assertSee(__('orders.imports.fields.storage_tier'))->assertSee(__('orders.imports.tier_bottom_rows', ['count' => 1]))->assertSee(__('orders.imports.tier_prefilled'))->assertDontSee('orders.imports.tier');
        $job = app(JobService::class)->create($client->id, 'loose');
        $this->actingAs($cs)->post(route('orders.imports.preview'), ['client_id' => $client->id, 'job_id' => $job['job_id'], 'requested_date' => today()->addWeek()->toDateString(), 'service_level' => 'standard', 'manifest' => $this->csv([$this->row('STAFF-1', '5', '底层')], 'staff.csv')])->assertSessionHasNoErrors();
        $this->assertSame('staff', OrderImport::query()->latest('id')->first()->errors['groups'][0]['rows'][0]['storage_tier_source']);
    }

    public function test_staff_change_a_goods_line_tier_and_its_stock_units_follow(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $supervisor = $this->staff('warehouse_supervisor');

        // The add-line form carries the tier select; a posted tier is a staff declaration.
        $this->actingAs($supervisor)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee('name="storage_tier"', false);
        $this->actingAs($supervisor)->post(route('warehouse.asns.lines.store', $asn), ['description' => 'Watches', 'expected_cartons' => 8, 'storage_tier' => 'standard'])->assertSessionHasNoErrors();
        $line = AsnLine::query()->where('asn_id', $asn->id)->sole();
        $this->assertSame(['standard', 'staff'], [$line->storage_tier, $line->storage_tier_source]);
        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 8, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 5], ['unit_type' => 'pallet', 'carton_qty' => 3]]], $this->location($warehouse, 'receiving'));

        $this->actingAs($this->staff('warehouse_operator'))->patch(route('warehouse.asns.lines.storage_tier.update', [$asn, $line]), ['storage_tier' => 'bottom'])->assertForbidden();
        $this->actingAs($this->clientUser($client))->patch(route('warehouse.asns.lines.storage_tier.update', [$asn, $line]), ['storage_tier' => 'bottom'])->assertForbidden();
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->patch(route('warehouse.asns.lines.storage_tier.update', [$asn, $line]), ['storage_tier' => 'top'])->assertSessionHasErrors('storage_tier');
        $this->actingAs($cs)->patch(route('warehouse.asns.lines.storage_tier.update', [$asn, $line]), ['storage_tier' => 'bottom'])
            ->assertRedirect(route('warehouse.asns.show', $asn).'#lines')
            ->assertSessionHas('status', __('warehouse.line_tier.updated', ['line' => $line->id, 'tier' => '底层']));

        $this->assertSame(['bottom', 'staff'], [$line->fresh()->storage_tier, $line->fresh()->storage_tier_source]);
        $this->assertSame(['bottom', 'bottom'], StockUnit::query()->whereKey(collect($units)->pluck('id'))->orderBy('id')->pluck('required_storage_tier')->all());
        $log = Activity::query()->where('subject_type', AsnLine::class)->where('subject_id', $line->id)->latest('id')->firstOrFail();
        $this->assertSame(['standard', 'bottom', $cs->id], [$log->properties['old']['storage_tier'], $log->properties['attributes']['storage_tier'], $log->causer_id]);

        $otherAsn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $this->actingAs($cs)->patch(route('warehouse.asns.lines.storage_tier.update', [$otherAsn, $line]), ['storage_tier' => 'standard'])->assertNotFound();
    }
}
