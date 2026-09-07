<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Services\SpreadsheetManifestParser;
use Tests\TestCase;

/** A4 uses the real supplied workbook, not a hard-coded Fake parser result. */
class SpreadsheetManifestParserTest extends TestCase
{
    public function test_real_dispatch_manifest_is_normalised_to_the_shared_contract(): void
    {
        $parsed = app(SpreadsheetManifestParser::class)->parse(base_path('data/需派送货物清单.xlsx'));

        $this->assertNotEmpty($parsed['rows']);
        $this->assertCount(135, $parsed['rows']);
        $this->assertCount(10, $parsed['errors']);
        $this->assertCount(5, array_unique(array_column($parsed['errors'], 'row')));
        $this->assertArrayHasKey('consignment_mark', $parsed['rows'][0]);
        $this->assertArrayHasKey('unit_price_cents', $parsed['rows'][0]);
        $this->assertArrayHasKey('length_mm', $parsed['rows'][0]);
        $this->assertArrayHasKey('raw_json', $parsed['rows'][0]);
        $this->assertArrayHasKey('raw_rows', $parsed);
        $this->assertIsInt($parsed['rows'][0]['carton_qty']);
        $this->assertGreaterThan(0, $parsed['rows'][0]['actual_weight_kg']);
    }
}
