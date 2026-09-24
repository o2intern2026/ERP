<?php

namespace Tests\Feature\Warehouse;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** CHANGE_REQUESTS #150: the scan page's camera runs on html5-qrcode (iPhone Safari has no BarcodeDetector) with clear HTTPS / permission hints. */
class ScanPageTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_the_scan_page_loads_the_sanctioned_barcode_library_and_carries_the_camera_hints(): void
    {
        $page = $this->actingAs($this->staff('warehouse_operator'))->get(route('warehouse.scan.index'))->assertOk()
            ->assertSee('https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js', false)
            ->assertSee('id="camera-reader"', false)->assertSee('new Html5Qrcode(', false)->assertSee("'BarcodeDetector' in window", false)
            ->assertSee(__('warehouse.scan.camera'))->assertSee(__('warehouse.scan.camera_close'))
            ->assertSee(__('warehouse.scan.camera_https'))->assertSee(__('warehouse.scan.camera_error'))->assertSee(__('warehouse.scan.camera_unsupported'));
        $this->assertDoesNotMatchRegularExpression('/warehouse\.scan\./', $page->getContent());
    }
}
