<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Picqer\Barcode\Types\TypeCode128;

/**
 * B11: carton / pallet unit labels and location labels as 100 × 150 mm PDF pages, Code 128.
 * CHANGE_REQUESTS #131 (audit 2026-09-22 INBOUND-01 / INBOUND-14): the barcode encodes the short scan token (`U<id>` / `L<id>`,
 * ScanCodes) so it fits the 88 mm printable width; the text under it keeps the full code. Labels are English only (lead
 * instruction 全英文): the description prints only when it has no CJK characters — the label font has none.
 * CHANGE_REQUESTS #141 (audit 2026-09-22 CRAWL-01): each barcode is ONE PNG <img> (GD) instead of ~100 absolutely positioned divs, and a
 * document holds at most BATCH_SIZE labels — 80 HTML-barcode labels exhausted PHP's 128 MB in dompdf; the controller splits the rest into
 * numbered batches.
 */
final class LabelService
{
    private const PAGE_100x150_PT = [0, 0, 283.46, 425.2];

    /** Labels per PDF document; longer sets are printed as batch 1..n (CRAWL-01). */
    public const BATCH_SIZE = 40;

    /** Width of one Code 128 module in px (dompdf renders at 96 dpi → 0.53 mm, comfortable for a 203 dpi thermal printer). */
    public const WIDTH_FACTOR = 2;

    public const BAR_HEIGHT_PX = 70;

    /** 100 mm page − 2 × 6 mm @page margin, in dompdf's 96 dpi px. The fit test compares barcodeWidthPx() against this. */
    public const PRINTABLE_WIDTH_PX = (100 - 2 * 6) / 25.4 * 96;

    /** CJK punctuation, CJK unified ideographs (+ extension A) and full-width forms — the same class PdfEnglishTest scans for. */
    private const CJK = '/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';

    public function unitLabels(Collection $units): string
    {
        return Pdf::loadView('warehouse::labels.units', [
            'units' => $units->load(['asnLine.asn.client', 'asnLine.asn.job', 'location']),
            'barcodes' => $units->mapWithKeys(fn (StockUnit $u) => [$u->id => $this->barcode(ScanCodes::unit($u->id))]),
        ])->setPaper(self::PAGE_100x150_PT)->output();
    }

    public function locationLabels(Collection $locations): string
    {
        return Pdf::loadView('warehouse::labels.locations', [
            'locations' => $locations->load('warehouse'),
            'barcodes' => $locations->mapWithKeys(fn (Location $l) => [$l->id => $this->barcode(ScanCodes::location($l->id))]),
        ])->setPaper(self::PAGE_100x150_PT)->output();
    }

    /** The Code 128 of one scan token as a single <img> (PNG data URI, GD) at the label's width factor and bar height. */
    public function barcode(string $code): string
    {
        $generator = new BarcodeGeneratorPNG;
        $generator->useGd();
        $png = $generator->getBarcode($code, BarcodeGeneratorPNG::TYPE_CODE_128, self::WIDTH_FACTOR, self::BAR_HEIGHT_PX);
        $width = (int) self::barcodeWidthPx($code);

        return sprintf('<img class="barcode-img" src="data:image/png;base64,%s" width="%d" height="%d" alt="%s" style="width:%dpx;height:%dpx">', base64_encode($png), $width, self::BAR_HEIGHT_PX, e($code), $width, self::BAR_HEIGHT_PX);
    }

    /**
     * The labels of batch $batch (1-based) out of $items, BATCH_SIZE per batch; the number of batches a set needs is batches().
     *
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @return Collection<int, T>
     */
    public static function batch(Collection $items, int $batch): Collection
    {
        return $items->slice(max(0, $batch - 1) * self::BATCH_SIZE, self::BATCH_SIZE)->values();
    }

    public static function batches(int $total): int
    {
        return max(1, (int) ceil($total / self::BATCH_SIZE));
    }

    /** Rendered width in px of the Code 128 for $code, exactly as barcode() lays it out (modules × width factor). */
    public static function barcodeWidthPx(string $code): float
    {
        return (new TypeCode128)->getBarcode($code)->getWidth() * self::WIDTH_FACTOR;
    }

    /** The description line of a unit label: trimmed to 60 characters, or omitted (null) when it carries CJK characters. */
    public static function printableDescription(?string $text): ?string
    {
        $text = trim((string) $text);
        if ($text === '' || preg_match(self::CJK, $text) === 1) {
            return null;
        }

        return mb_strlen($text) > 60 ? rtrim(mb_substr($text, 0, 59)).'…' : $text;
    }
}
