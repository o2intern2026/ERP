<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\LabelService;
use App\Modules\Warehouse\Services\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 CRAWL-01 (CR #141): 80 HTML-barcode labels in one dompdf document exhausted PHP's 128 MB, and the location print always
 * printed every location of the warehouse. Now a PDF holds at most 40 labels (larger sets → a batch-links page, `?batch=n` → that slice),
 * every barcode is one PNG <img>, and location labels take zone / aisle-range / type filters.
 */
class LabelBatchTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** Pages in a dompdf document: one `/Type /Page` object per page. */
    private function pageCount(string $pdf): int
    {
        return preg_match_all('~/Type\s*/Page\b~', $pdf);
    }

    public function test_unit_labels_over_forty_come_as_numbered_batches_of_one_page_per_label(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Boxes', 'expected_cartons' => 41, 'consignment_mark' => 'BT']]);
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 41, 'units' => array_fill(0, 41, ['unit_type' => 'carton', 'carton_qty' => 1])], $this->location($warehouse, 'receiving'));
        $this->assertSame(41, StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $line->id)->count());

        // No batch asked for and 41 > 40 → the HTML page with two links, no PDF rendered.
        $page = $this->actingAs($supervisor)->get(route('warehouse.labels.asn', $asn))->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $page->assertSee(__('warehouse.labels.batches_hint', ['total' => 41, 'size' => 40, 'batches' => 2]))
            ->assertSee(__('warehouse.labels.batch_link', ['n' => 1, 'from' => 1, 'to' => 40]))->assertSee(__('warehouse.labels.batch_link', ['n' => 2, 'from' => 41, 'to' => 41]))
            ->assertSee(route('warehouse.labels.asn', ['asn' => $asn->id, 'batch' => 2]), false);

        // Each batch is a PDF of exactly its labels; a batch past the end is a 404.
        $first = $this->actingAs($supervisor)->get(route('warehouse.labels.asn', ['asn' => $asn->id, 'batch' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(40, $this->pageCount($first->getContent()));
        $second = $this->actingAs($supervisor)->get(route('warehouse.labels.asn', ['asn' => $asn->id, 'batch' => 2]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, $this->pageCount($second->getContent()));
        $this->actingAs($supervisor)->get(route('warehouse.labels.asn', ['asn' => $asn->id, 'batch' => 3]))->assertNotFound();

        // A set that fits one batch prints straight away (the unit page's single-label link keeps working); 41 ids also batch.
        $ids = StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $line->id)->orderBy('id')->pluck('id');
        $this->actingAs($supervisor)->get(route('warehouse.labels.units', ['ids' => [$ids->first()]]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($supervisor)->get(route('warehouse.labels.units', ['ids' => $ids->all()]))->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertSee('id="label-batches"', false);
        $this->assertSame(40, $this->pageCount($this->actingAs($supervisor)->get(route('warehouse.labels.units', ['ids' => $ids->all(), 'batch' => 1]))->getContent()));

        // The barcode is one <img> per label — the view carries 40 images and no positioned-div barcodes.
        $slice = LabelService::batch(StockUnit::query()->withoutGlobalScopes()->whereKey($ids)->orderBy('id')->get(), 1);
        $labels = app(LabelService::class);
        $html = view('warehouse::labels.units', ['units' => $slice->load(['asnLine.asn.client', 'asnLine.asn.job', 'location']), 'barcodes' => $slice->mapWithKeys(fn (StockUnit $u) => [$u->id => $labels->barcode('U'.$u->id)])])->render();
        $this->assertSame(40, substr_count($html, '<img class="barcode-img"'));
        $this->assertStringNotContainsString('position:absolute', $html);
        $this->assertSame([2, 1, 3], [LabelService::batches(41), LabelService::batches(40), LabelService::batches(81)]);
    }

    public function test_location_labels_filter_by_zone_aisle_range_and_type_and_the_page_offers_the_filter(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse(); // RCV-01-01, A-01-01, A-01-02, PF-01-01, QA-01-01
        foreach (['02', '03', '10'] as $aisle) {
            Location::query()->create(['warehouse_id' => $warehouse->id, 'zone' => 'A', 'aisle' => $aisle, 'bin' => '01', 'full_code' => Location::buildFullCode('MEL', 'A', $aisle, '01'), 'type' => 'storage', 'active' => true]);
        }

        $this->actingAs($supervisor)->get(route('warehouse.locations.index'))->assertOk()
            ->assertSee(__('warehouse.labels.print_filter'))->assertSee('name="aisle_from"', false)->assertSee('name="zone"', false)->assertSee('<option value="A">A</option>', false);

        $pdf = fn (array $query) => $this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id] + $query))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertSame(8, $this->pageCount($pdf([])), 'no filter = every active location, as before');
        $this->assertSame(5, $this->pageCount($pdf(['zone' => 'a'])), 'zone A: aisles 01 (2 bins), 02, 03, 10 — case-insensitive');
        $this->assertSame(3, $this->pageCount($pdf(['zone' => 'A', 'aisle_from' => '02', 'aisle_to' => '10'])), 'aisles compare as numbers: 02, 03, 10 (not 1 < "10" < "2")');
        $this->assertSame(1, $this->pageCount($pdf(['zone' => 'A', 'aisle_from' => '10'])));
        $this->assertSame(1, $this->pageCount($pdf(['type' => 'quarantine'])));
        $this->assertSame(1, $this->pageCount($pdf(['type' => 'pickface', 'zone' => 'PF'])));
        $this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id, 'zone' => 'ZZ']))->assertNotFound();
        $this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id, 'type' => 'bogus']))->assertSessionHasErrors('type');

        // 41+ locations → the batch page, with the filters repeated in every link.
        foreach (range(1, 40) as $bin) {
            Location::query()->create(['warehouse_id' => $warehouse->id, 'zone' => 'B', 'aisle' => '01', 'bin' => sprintf('%02d', $bin), 'full_code' => Location::buildFullCode('MEL', 'B', '01', sprintf('%02d', $bin)), 'type' => 'storage', 'active' => true]);
        }
        $this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id, 'zone' => 'B']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $page = $this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id]))->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $page->assertSee(__('warehouse.labels.batches_hint', ['total' => 48, 'size' => 40, 'batches' => 2]))->assertSee('batch=2', false);
        $this->assertSame(8, $this->pageCount($this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id, 'batch' => 2]))->getContent()));
        $filtered = $this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id, 'type' => 'storage']))->assertOk();
        $filtered->assertSee('type=storage', false)->assertSee(__('warehouse.labels.batches_hint', ['total' => 45, 'size' => 40, 'batches' => 2]));
    }
}
