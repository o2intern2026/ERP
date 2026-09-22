<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\LabelService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\ScanCodes;
use App\Modules\Warehouse\Services\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-01 / INBOUND-14 (CHANGE_REQUESTS #131): the label barcode encodes the short token U<id> / L<id> so it fits the
 * 88 mm printable width (Code 128 of the 24-character label_code ran 147 mm), every scan input accepts the token or the full code in
 * either case, one location label = one page (the old 138 mm + 20 mm padding spilled a blank page after each), and the unit label
 * is English only: mark + cartons largest, BOTTOM LEVEL boxed, the location in small print, a CJK description omitted.
 */
class LabelScanCodeTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const CJK = '/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';

    public function test_short_token_barcodes_fit_the_label_and_every_label_is_exactly_one_page(): void
    {
        // The generator's own width for the longest ids that can exist (12 digits is far beyond any unit / location count) stays under 88 mm;
        // the full codes the labels used to encode did not, which is why the barcode changed.
        foreach ([ScanCodes::unit(999_999_999_999), ScanCodes::location(999_999_999_999)] as $token) {
            $this->assertLessThan(LabelService::PRINTABLE_WIDTH_PX, LabelService::barcodeWidthPx($token), "$token must fit the printable width");
        }
        foreach (['ASN-20260921-0003-L17-01', 'ASN-20260921-0003-L17-DMG', 'MEL-RCV-01-01'] as $full) {
            $this->assertGreaterThan(LabelService::PRINTABLE_WIDTH_PX, LabelService::barcodeWidthPx($full), "$full is why the barcode carries the short token");
        }
        // CRAWL-01 (CR #141): the barcode is one PNG <img> at the same rendered width, not ~100 positioned divs.
        $barcode = app(LabelService::class)->barcode('U12');
        $this->assertMatchesRegularExpression('/^<img class="barcode-img" src="data:image\/png;base64,[A-Za-z0-9+\/=]+" width="'.(int) LabelService::barcodeWidthPx('U12').'"/', $barcode);
        $this->assertSame(1, substr_count($barcode, '<'), 'a single element per barcode');

        $this->actingAs($this->staff('warehouse_supervisor'));
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['lines' => $lines, 'units' => $units] = $this->stockedAsn($client, $warehouse, [
            ['mark' => 'M2', 'description' => '折叠椅 / Folding chairs', 'cartons' => 10],
            ['mark' => 'M3', 'description' => 'Bluetooth speakers', 'cartons' => 4, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 4, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'weight_kg' => 300, 'pallet_source' => 'client_own']]],
        ]);
        app(AsnService::class)->setLineStorageTier($lines[1], 'bottom'); // the pallet is declared bottom level → boxed on its label
        $unitModels = StockUnit::query()->whereKey(collect($units)->pluck('id'))->orderBy('id')->get();
        $labels = app(LabelService::class);

        $pdf = $labels->unitLabels($unitModels);
        $this->assertSame(2, $this->pageCount($pdf), 'one unit label = one page');
        $this->assertSame(5, $this->pageCount($labels->locationLabels(Location::query()->where('warehouse_id', $warehouse->id)->get())), 'one location label = one page');
        $this->assertSame(1, $this->pageCount($labels->locationLabels(Location::query()->where('warehouse_id', $warehouse->id)->where('type', 'quarantine')->get())), 'the audit repro: 1 location → 1 page, not 2');

        // The rendered content, English only (lead instruction 全英文): mark + cartons is the largest line, the tier is boxed, the description
        // with Chinese is omitted while the English one prints, the location is small print, and the full label_code is still readable.
        $html = view('warehouse::labels.units', ['units' => $unitModels, 'barcodes' => $unitModels->mapWithKeys(fn (StockUnit $u) => [$u->id => $labels->barcode(ScanCodes::unit($u->id))])])->render();
        $this->assertDoesNotMatchRegularExpression(self::CJK, $html);
        $this->assertStringContainsString('<div class="big">M2 · 10 ctn</div>', $html);
        $this->assertStringContainsString('<div class="big">M3 · 4 ctn</div>', $html);
        $this->assertStringNotContainsString('Folding chairs', $html, 'a description with CJK is left off the label');
        $this->assertStringContainsString('Bluetooth speakers', $html);
        $this->assertSame(1, substr_count($html, __('pdf.storage_tiers.bottom')), 'only the bottom-level pallet carries the box');
        $this->assertStringContainsString('Received at MEL-A-01-01', $html);
        $this->assertStringContainsString($unitModels[0]->label_code, $html);
        $this->assertStringContainsString('Pallet · 4 ctn · 1200×1200×1400 mm · 300.000 kg', $html);
        $this->assertStringNotContainsString('<div class="big">MEL-', $html, 'the location is no longer the biggest line');

        // The routes still serve PDFs.
        $this->get(route('warehouse.labels.units', ['ids' => $unitModels->pluck('id')->all()]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_every_scan_input_accepts_the_short_token_or_the_full_code_in_either_case(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['units' => [$unit]] = $this->stockedAsn($client, $warehouse, [['mark' => 'SCAN-1', 'cartons' => 6]]);
        $storage = Location::query()->where('warehouse_id', $warehouse->id)->where('full_code', 'MEL-A-01-02')->firstOrFail();
        $pickface = $this->location($warehouse, 'pickface');

        // 扫码 page: the token (any case) and the full code resolve to the same unit / location.
        foreach (['u'.$unit->id, 'U'.$unit->id, strtolower($unit->label_code), $unit->label_code] as $code) {
            $this->get(route('warehouse.scan.resolve', ['code' => $code]))->assertRedirect(route('warehouse.stock.show', $unit));
        }
        foreach (['l'.$storage->id, 'L'.$storage->id, 'mel-a-01-02'] as $code) {
            $this->get(route('warehouse.scan.resolve', ['code' => $code]))->assertRedirect(route('warehouse.index', ['location' => $storage->full_code]));
        }
        $this->get(route('warehouse.scan.resolve', ['code' => 'U'.($unit->id + 1000)]))->assertRedirect(route('warehouse.scan.index'))->assertSessionHasErrors('code');

        // Move by the location token; restore / putaway go through the same scope.
        $this->post(route('warehouse.stock.move', $unit), ['location_code' => 'l'.$storage->id])->assertSessionHasNoErrors();
        $this->assertSame($storage->id, $unit->fresh()->location_id);

        // Putaway of a pending unit by the token of a location in its warehouse.
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'More', 'expected_cartons' => 2]]);
        [$pending] = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 2, 'units' => [['unit_type' => 'carton', 'carton_qty' => 2]]], $this->location($warehouse, 'receiving'));
        $this->post(route('warehouse.putaway.store', $pending), ['location_code' => 'L'.$pickface->id])->assertSessionHasNoErrors();
        $this->assertTrue($pending->fresh()->putaway_completed);
        $this->assertSame($pickface->id, $pending->fresh()->location_id);

        // Stocktake: opened on a location by its token, the unit counted by scanning its token.
        $this->post(route('warehouse.stocktakes.store'), ['warehouse_id' => $warehouse->id, 'location_code' => 'l'.$storage->id])->assertSessionHasNoErrors();
        $stocktake = app(StocktakeService::class)->open(['warehouse_id' => $warehouse->id, 'client_id' => $client->id, 'location_id' => null]);
        $this->post(route('warehouse.stocktakes.scan', $stocktake), ['code' => 'u'.$unit->id])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('warehouse.stocktakes.scanned', ['label' => $unit->label_code]));
        $this->assertSame(6, $stocktake->lines()->where('stock_unit_id', $unit->id)->value('counted_qty'));
        $this->post(route('warehouse.stocktakes.scan', $stocktake), ['code' => 'U999999'])->assertSessionHasErrors('code');
    }

    /** Pages in a dompdf document: one `/Type /Page` object per page (`/Type /Pages` is the tree, excluded by the word boundary). */
    private function pageCount(string $pdf): int
    {
        return preg_match_all('~/Type\s*/Page\b~', $pdf);
    }
}
