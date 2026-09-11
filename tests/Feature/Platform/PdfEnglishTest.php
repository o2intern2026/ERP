<?php

namespace Tests\Feature\Platform;

use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Picqer\Barcode\BarcodeGeneratorHTML;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Lead request 2026-09-11 (CHANGE_REQUESTS #114): every PDF a client or carrier receives — 入库单 / goods receipt, tax invoice,
 * unit and location labels, consignment note, own-fleet shipping label, proof of delivery — prints in English while the screens
 * stay Chinese. The PDF templates draw every word from lang/en/pdf.php (the only lang/en file, reached through the fallback
 * locale, so no locale switch at render time) and never from lang/zh, so a Chinese screen label cannot leak onto a document.
 */
class PdfEnglishTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** CJK punctuation, CJK unified ideographs (+ extension A) and full-width forms. Not \p{Han}: PCRE2 matches it through Script_Extensions, so the middle dot (U+00B7) would count as Chinese. */
    private const CJK = '/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';

    private const TEMPLATES = [
        'app/Modules/Warehouse/views/receipts/pdf.blade.php',
        'app/Modules/Warehouse/views/labels/units.blade.php',
        'app/Modules/Warehouse/views/labels/locations.blade.php',
        'app/Modules/Billing/views/invoices/pdf.blade.php',
        'app/Modules/Transport/views/shipments/consignment-note.blade.php',
        'app/Modules/Transport/views/shipments/label.blade.php',
        'app/Modules/Transport/views/driver/pod-pdf.blade.php',
    ];

    public function test_pdf_templates_draw_every_word_from_the_english_pdf_strings(): void
    {
        $this->assertSame(['pdf.php'], array_map('basename', glob(base_path('lang/en/*.php')) ?: []), 'lang/en holds the PDF strings only; the screens stay lang/zh');
        $this->assertDoesNotMatchRegularExpression(self::CJK, (string) json_encode(require base_path('lang/en/pdf.php'), JSON_UNESCAPED_UNICODE), 'lang/en/pdf.php must be English');

        foreach (self::TEMPLATES as $template) {
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(base_path($template)));
            $this->assertDoesNotMatchRegularExpression(self::CJK, $source, "$template carries a Chinese literal");
            $this->assertStringContainsString('<html lang="en">', $source, "$template is not declared English");
            $this->assertStringNotContainsString('__("', $source, "$template: use single-quoted keys so this scan sees them");

            preg_match_all("/(?:__|trans|@lang)\\(\\s*'([^']+)'/", $source, $matches);
            $this->assertNotEmpty($matches[1], "$template has no translated strings");
            foreach (array_unique($matches[1]) as $key) {
                $this->assertStringStartsWith('pdf.', $key, "$template prints a screen (lang/zh) string: $key");
                $this->assertTrue(Lang::has(rtrim($key, '.')), "$template: '$key' is missing from lang/en/pdf.php"); // dynamic keys end with a dot
            }
        }
    }

    public function test_goods_receipt_and_labels_render_in_english_while_the_screens_stay_chinese(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $client = $this->client(['name' => 'Edward Logistics']);
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'units' => $units] = $this->stockedAsn($client, $warehouse, [['mark' => 'EDW-01', 'description' => 'Bluetooth speakers', 'cartons' => 12]]);
        $receipt = GoodsReceipt::query()->firstOrFail()->load(['asn.containers', 'client', 'warehouse', 'job', 'openedBy', 'completedBy', 'lines.asnLine.container']);

        $html = view('warehouse::receipts.pdf', [
            'receipt' => $receipt, 'asn' => $asn, 'labels' => collect(), 'totalBatches' => 1,
            'rollup' => app(GoodsReceiptService::class)->rollup($asn), 'cjkFont' => null,
        ])->render();
        foreach (['Goods Receipt', 'Receipt no.', 'ASN no.', 'Expected', 'Received', 'Damaged', 'Variance', 'Container', 'Client representative', 'EDW-01', 'Bluetooth speakers', 'Edward Logistics'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertDoesNotMatchRegularExpression(self::CJK, $html, 'the goods receipt must not print a Chinese label');
        $this->assertStringNotContainsString('pdf.', $html, 'no raw lang key on the goods receipt');

        $generator = new BarcodeGeneratorHTML;
        $unitModels = StockUnit::query()->whereKey(collect($units)->pluck('id'))->get()->load(['asnLine.asn.client', 'asnLine.asn.job', 'location']);
        $labels = view('warehouse::labels.units', [
            'units' => $unitModels,
            'barcodes' => $unitModels->mapWithKeys(fn (StockUnit $u) => [$u->id => $generator->getBarcode($u->label_code, BarcodeGeneratorHTML::TYPE_CODE_128, 2, 70)]),
        ])->render();
        $this->assertStringContainsString('Carton · 12 ctn', $labels);
        $this->assertStringContainsString('Edward Logistics', $labels);
        $this->assertDoesNotMatchRegularExpression(self::CJK, $labels, 'unit labels must be English');

        $locations = Location::query()->where('warehouse_id', $warehouse->id)->get()->load('warehouse');
        $this->assertTrue($locations->isNotEmpty());
        $locationLabels = view('warehouse::labels.locations', [
            'locations' => $locations,
            'barcodes' => $locations->mapWithKeys(fn (Location $l) => [$l->id => $generator->getBarcode($l->full_code, BarcodeGeneratorHTML::TYPE_CODE_128, 2, 70)]),
        ])->render();
        $this->assertStringContainsString(__('pdf.location_types.storage'), $locationLabels);
        $this->assertDoesNotMatchRegularExpression(self::CJK, $locationLabels, 'location labels must be English');

        // The screens are still Chinese: the 入库单 page and the button that opens the (English) PDF.
        $this->get(route('warehouse.receipts.show', $receipt))->assertOk()
            ->assertSee(__('warehouse.receipts.pdf_button'))
            ->assertSee(__('warehouse.receipt_statuses.'.$receipt->status));
        $this->assertMatchesRegularExpression(self::CJK, __('warehouse.receipts.pdf_button'));
        $this->get(route('warehouse.receipts.pdf', $receipt))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
