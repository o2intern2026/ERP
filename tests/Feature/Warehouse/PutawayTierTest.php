<?php

namespace Tests\Feature\Warehouse;

use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnImportService;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\MoveService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\ReturnService;
use App\Modules\Warehouse\Services\StockLedger;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #126 putaway (lead answers 2, 3, 6, 8): a declared-bottom GOOD PALLET into a storage location that is not bottom is
 * refused unless a reason is given; a standard pallet into a bottom location is allowed with a warning; cartons, pickface and quarantine
 * are exempt; the page hints the first FREE bottom location; the stock list shows partly picked pallets still in bottom locations.
 */
class PutawayTierTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** @return list<StockUnit> received units of one goods line with the given declared tier */
    private function received(Client $client, Warehouse $warehouse, string $tier, array $units, string $mark = 'MK'): array
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['consignment_mark' => $mark, 'description' => 'Goods', 'expected_cartons' => array_sum(array_column($units, 'carton_qty')), 'storage_tier' => $tier]]);

        return app(ReceivingService::class)->receiveLine($line, ['received_cartons' => array_sum(array_column($units, 'carton_qty')), 'units' => $units], $this->location($warehouse, 'receiving'));
    }

    private function bottomLocations(Warehouse $warehouse): array
    {
        return [
            Location::query()->create(['warehouse_id' => $warehouse->id, 'full_code' => 'MEL-B-01-01', 'zone' => 'B', 'aisle' => '01', 'bin' => '01', 'type' => 'storage', 'storage_tier' => 'bottom', 'rack_level' => 1, 'active' => true]),
            Location::query()->create(['warehouse_id' => $warehouse->id, 'full_code' => 'MEL-B-01-02', 'zone' => 'B', 'aisle' => '01', 'bin' => '02', 'type' => 'storage', 'storage_tier' => 'bottom', 'rack_level' => 1, 'active' => true]),
        ];
    }

    public function test_a_bottom_pallet_into_a_standard_location_is_refused_in_chinese_and_accepted_with_a_stored_reason(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $client = $this->client();
        $warehouse = $this->warehouse();
        [$b1, $b2] = $this->bottomLocations($warehouse);
        [$pallet] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 20]]);
        $this->assertSame('bottom', $pallet->fresh()->required_storage_tier, 'receiving copies the goods line tier onto the unit');

        // The page shows the declared tier and the first free bottom location.
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index'))->assertOk()
            ->assertSee(__('warehouse.putaway.required_tier'))->assertSee(__('warehouse.putaway.bottom_hint', ['code' => 'MEL-B-01-01']))->assertDontSee('name="tier_reason"', false);

        $message = __('warehouse.putaway.errors.tier_mismatch', ['label' => $pallet->label_code, 'code' => 'MEL-A-01-01']);
        $this->actingAs($supervisor)->from(route('warehouse.putaway.index'))->post(route('warehouse.putaway.store', $pallet), ['location_code' => 'mel-a-01-01'])
            ->assertRedirect(route('warehouse.putaway.index'))->assertSessionHasErrors(['location_code' => $message]);
        $this->assertFalse($pallet->fresh()->putaway_completed);
        $this->assertMatchesRegularExpression('/\p{Han}/u', $message);
        // The refused row re-opens with a reason input. Scan-gun flow (review 2026-09-15): the refused code stays in its field, which keeps
        // the focus with the text selected so the next scan REPLACES it; the reason input is not browser-required (scanning a bottom
        // location with no reason must submit) and never takes the focus (a scan must not land in it).
        $html = $this->actingAs($supervisor)->get(route('warehouse.putaway.index'))->assertOk()->assertSee('name="tier_reason"', false)->getContent();
        $this->assertSame(1, preg_match('/<input[^>]*name="location_code"[^>]*value="mel-a-01-01"[^>]*>/', $html, $codeInput));
        $this->assertSame(1, preg_match('/<input[^>]*name="tier_reason"[^>]*>/', $html, $reasonInput));
        $this->assertStringContainsString('autofocus', $codeInput[0]);
        $this->assertStringContainsString('this.select()', $codeInput[0]);
        $this->assertStringNotContainsString('required', $reasonInput[0]);
        $this->assertStringNotContainsString('autofocus', $reasonInput[0]);

        $this->actingAs($supervisor)->post(route('warehouse.putaway.store', $pallet), ['location_code' => 'MEL-A-01-01', 'tier_reason' => '底层满了,明天并托'])->assertSessionHasNoErrors();
        $pallet->refresh();
        $this->assertTrue($pallet->putaway_completed);
        $this->assertSame(['MEL-A-01-01', '底层满了,明天并托'], [$pallet->location->full_code, $pallet->storage_tier_override_reason]);

        // Service level: into a bottom location needs no reason; MoveService has no tier rule (lead answer 8).
        [$second] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 10]], 'MK2');
        try {
            app(PutawayService::class)->putaway($second, $this->location($warehouse, 'storage'));
            $this->fail('tier mismatch must be refused');
        } catch (RuleViolation $e) {
            $this->assertSame('warehouse.putaway.errors.tier_mismatch', $e->langKey());
        }
        // The refusal's first remedy — scan a bottom location, reason left empty — puts the pallet away.
        $this->actingAs($supervisor)->post(route('warehouse.putaway.store', $second), ['location_code' => $b2->full_code, 'tier_reason' => ''])->assertSessionHasNoErrors();
        $this->assertSame($b2->full_code, $second->fresh()->location->full_code);
        $this->assertNull($second->fresh()->storage_tier_override_reason);
        app(MoveService::class)->move($second->fresh(), $this->location($warehouse, 'storage'), 'consolidate');
        $this->assertSame('MEL-A-01-01', $second->fresh()->location->full_code);
    }

    /** Review 2026-09-15 (TEST-4): the staff 预报单 manifest import maps 存储等级 onto the goods lines (declared by staff). */
    public function test_the_staff_asn_manifest_import_carries_the_declared_tier_onto_the_goods_lines(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $headers = '唛头,中文品名,英文品名,包装类型,箱数,产品数量,实重(KG),长(CM),宽(CM),高(CM),收件人,电话,地址,城区,州,邮编,FBA参考号,要求送达日,存储等级';
        $row = fn (string $mark, string $tier) => "{$mark},手表,Watch,纸箱,10,100,85,60,40,40,Shop,0400 000 001,1 Warehouse Rd,Moorebank,NSW,2170,,,{$tier}";

        app(AsnImportService::class)->import($asn, UploadedFile::fake()->createWithContent('manifest.csv', "\xEF\xBB\xBF".implode("\n", [$headers, $row('MK-BOTTOM', '底层'), $row('MK-EMPTY', ''), $row('MK-STD', '标准')])."\n"), null);

        $lines = AsnLine::query()->where('asn_id', $asn->id)->get()->keyBy('consignment_mark');
        $this->assertSame(['bottom', 'staff'], [$lines['MK-BOTTOM']->storage_tier, $lines['MK-BOTTOM']->storage_tier_source]);
        $this->assertSame(['standard', null], [$lines['MK-EMPTY']->storage_tier, $lines['MK-EMPTY']->storage_tier_source], 'an empty cell is no declaration');
        $this->assertSame(['standard', 'staff'], [$lines['MK-STD']->storage_tier, $lines['MK-STD']->storage_tier_source]);
    }

    /** Review 2026-09-15 (TEST-4): a return of a bottom goods line comes back as a unit that still requires the bottom tier. */
    public function test_a_returned_unit_of_a_bottom_goods_line_keeps_the_declared_tier(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'R1', 'cartons' => 10]]);
        $asnLines[0]->update(['storage_tier' => 'bottom', 'storage_tier_source' => 'staff']);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 4]]);

        $returns = app(ReturnService::class);
        $receipt = $returns->open(['original_order_id' => $order->id, 'warehouse_id' => $warehouse->id]);
        [$line] = $receipt->lines;
        $returns->receiveLine($line, 4, 'good');
        $returns->completeReceiving($receipt->fresh());
        $returns->inspectLine($line->fresh(), 'available', $this->staff('warehouse_supervisor')->id);

        $this->assertSame('bottom', $line->fresh()->stockUnit->required_storage_tier);
    }

    public function test_a_standard_pallet_into_bottom_warns_and_cartons_pickface_and_quarantine_are_exempt_and_the_hint_skips_occupied_locations(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $client = $this->client();
        $warehouse = $this->warehouse();
        [$b1] = $this->bottomLocations($warehouse);

        [$standardPallet] = $this->received($client, $warehouse, 'standard', [['unit_type' => 'pallet', 'carton_qty' => 12]]);
        $this->actingAs($supervisor)->post(route('warehouse.putaway.store', $standardPallet), ['location_code' => 'MEL-B-01-01'])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('warehouse.putaway.done', ['label' => $standardPallet->label_code, 'location' => 'MEL-B-01-01']).' '.__('warehouse.putaway.standard_into_bottom', ['code' => 'MEL-B-01-01']));

        // MEL-B-01-01 now holds stock → the hint for the next bottom pallet is MEL-B-01-02.
        [$bottomPallet, $bottomCarton] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 8], ['unit_type' => 'carton', 'carton_qty' => 4]], 'MK-B');
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index'))->assertOk()
            ->assertSee(__('warehouse.putaway.bottom_hint', ['code' => 'MEL-B-01-02']))->assertDontSee(__('warehouse.putaway.bottom_hint', ['code' => 'MEL-B-01-01']));

        // Carton declared bottom → standard storage, no reason; bottom pallet → pickface, no reason.
        $this->actingAs($supervisor)->post(route('warehouse.putaway.store', $bottomCarton), ['location_code' => 'MEL-A-01-02'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.putaway.store', $bottomPallet), ['location_code' => 'MEL-PF-01-01'])->assertSessionHasNoErrors();
        $this->assertTrue($bottomCarton->fresh()->putaway_completed);
        $this->assertTrue($bottomPallet->fresh()->putaway_completed);
        $this->assertNull($bottomPallet->fresh()->storage_tier_override_reason);

        // A damaged bottom pallet goes to quarantine without a tier rule.
        [$held] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 5]], 'MK-Q');
        $held->update(['condition' => 'damaged']);
        app(PutawayService::class)->putaway($held->fresh(), $this->location($warehouse, 'quarantine'));
        $this->assertTrue($held->fresh()->putaway_completed);

        // With every bottom location occupied the page says so.
        [$last] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 5]], 'MK-L');
        app(PutawayService::class)->putaway($this->received($client, $warehouse, 'standard', [['unit_type' => 'pallet', 'carton_qty' => 3]], 'MK-S')[0], Location::query()->where('full_code', 'MEL-B-01-02')->sole());
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index'))->assertOk()->assertSee(__('warehouse.putaway.no_free_bottom'));
        $this->assertFalse($last->fresh()->putaway_completed);
    }

    public function test_the_stock_list_shows_partly_picked_pallets_still_in_bottom_locations_lowest_share_first(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $client = $this->client(['name' => 'Valuable Goods Co']);
        $warehouse = $this->warehouse();
        [$b1, $b2] = $this->bottomLocations($warehouse);
        [$full] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 20]], 'FULL');
        [$picked] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 20]], 'PICKED');
        [$mostlyPicked] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 10]], 'MOSTLY');
        [$standardSpot] = $this->received($client, $warehouse, 'bottom', [['unit_type' => 'pallet', 'carton_qty' => 10]], 'ELSEWHERE');
        app(PutawayService::class)->putaway($full, $b1);
        app(PutawayService::class)->putaway($picked, $b2);
        app(PutawayService::class)->putaway($mostlyPicked, $b2);
        app(PutawayService::class)->putaway($standardSpot, $this->location($warehouse, 'storage'), 'test');
        app(StockLedger::class)->record($picked->fresh(), 'pick', -5, ['source_type' => 'task', 'source_id' => 1]);
        app(StockLedger::class)->record($mostlyPicked->fresh(), 'pick', -8, ['source_type' => 'task', 'source_id' => 1]);
        app(StockLedger::class)->record($standardSpot->fresh(), 'pick', -5, ['source_type' => 'task', 'source_id' => 1]);

        $html = $this->actingAs($supervisor)->get(route('warehouse.index', ['bottom_leftover' => 1]))->assertOk()->assertSee(__('warehouse.stock.bottom_leftover.title'))->getContent();
        $card = substr($html, (int) strpos($html, 'id="bottom-leftover"'));
        $card = substr($card, 0, (int) strpos($card, '</article>'));
        $this->assertStringContainsString($picked->label_code, $card);
        $this->assertStringContainsString('15 / 20', $card);
        $this->assertStringContainsString('2 / 10', $card);
        $this->assertStringContainsString('Valuable Goods Co', $card);
        $this->assertStringContainsString('MEL-B-01-02', $card);
        $this->assertStringNotContainsString($full->label_code, $card, 'a full pallet is not listed');
        $this->assertStringNotContainsString($standardSpot->label_code, $card, 'a pallet in a standard location is not listed');
        $this->assertLessThan(strpos($card, $picked->label_code), strpos($card, $mostlyPicked->label_code), 'lowest remaining share first');

        $this->actingAs($supervisor)->get(route('warehouse.index'))->assertOk()->assertDontSee('id="bottom-leftover"', false)->assertSee(__('warehouse.stock.bottom_leftover.filter'));
    }
}
