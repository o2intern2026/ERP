<?php

namespace Tests\Feature\Platform;

use App\Modules\Orders\Services\AsnOrderService;
use App\Modules\Orders\Services\SpreadsheetManifestParser;
use App\Modules\Platform\Services\DatabaseOutboxPublisher;
use App\Support\Contracts\JobService;
use App\Support\Contracts\ManifestParser;
use App\Support\Contracts\OrderService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\StockService;
use App\Support\Contracts\TransportOptionService;
use App\Support\Fakes\FakeJobService;
use App\Support\Fakes\FakeManifestParser;
use App\Support\Fakes\FakeOrderService;
use App\Support\Fakes\FakeRateService;
use App\Support\Fakes\FakeStockService;
use App\Support\Fakes\FakeTransportOptionService;
use App\Support\Outbox\OutboxPublisher;
use Tests\TestCase;

/** The Fakes implement contracts/services.md and are bound when USE_FAKE_SERVICES=true (phpunit.xml). */
class FakeServicesTest extends TestCase
{
    public function test_every_contract_resolves_to_its_real_implementation(): void
    {
        $this->assertTrue(config('erp.use_fake_services'));
        $this->assertInstanceOf(\App\Modules\Warehouse\Services\StockService::class, app(StockService::class)); // real since M2
        $this->assertInstanceOf(AsnOrderService::class, app(OrderService::class)); // real since M3
        $this->assertInstanceOf(\App\Modules\Transport\Services\TransportOptionService::class, app(TransportOptionService::class)); // real since M5
        $this->assertInstanceOf(\App\Modules\Billing\Services\RateService::class, app(RateService::class)); // real since M6 (billing block)
        $this->assertInstanceOf(\App\Modules\Platform\Services\JobService::class, app(JobService::class)); // real since M1
        $this->assertInstanceOf(SpreadsheetManifestParser::class, app(ManifestParser::class)); // real since M3
        $this->assertInstanceOf(DatabaseOutboxPublisher::class, app(OutboxPublisher::class)); // real since M1
    }

    public function test_fake_rate_service_prices_the_edward_card_and_never_returns_zero_for_missing_rates(): void
    {
        $rates = new FakeRateService;

        $putaway = $rates->price(1, 'WH-PUTAWAY-PLT', 3);
        $this->assertSame(1350, $putaway['amount_cents']);
        $this->assertSame('pallet', $putaway['uom']);
        $this->assertFalse($putaway['missing_rate']);
        $this->assertFalse($putaway['is_poa']);

        $mixed = $rates->price(1, 'WH-DEVAN-20-MIXED', 1);
        $this->assertTrue($mixed['is_poa']);
        $this->assertNull($mixed['amount_cents']);

        $this->assertTrue($rates->price(1, 'WH-DEVAN-20-LOOSE', 1, ['line_count' => 25])['is_poa']);
        $this->assertSame(40000, $rates->price(1, 'WH-DEVAN-20-LOOSE', 1, ['line_count' => 20])['amount_cents']);
        $this->assertTrue($rates->price(1, 'TR-CARTAGE-20', 1, ['gross_weight_kg' => 23000])['is_poa']);

        $missing = $rates->price(1, 'TR-DELIVERY-BASE', 1);
        $this->assertTrue($missing['missing_rate']);
        $this->assertNull($missing['amount_cents']);

        $waste = $rates->price(1, 'VAS-WASTE-CBM', 0.4);
        $this->assertSame(8000, $waste['amount_cents']);
        $this->assertTrue($waste['min_charge_applied']);
        $this->assertSame(1.0, $waste['qty']);
    }

    public function test_fake_rate_service_exposes_thresholds_and_suggests_pallet_class_per_plan_4_8(): void
    {
        $rates = new FakeRateService;

        $this->assertSame(25, $rates->thresholds(1, 'TR-TAILGATE')['tailgate_weight_kg']);
        $this->assertNull($rates->thresholds(1, 'WH-PUTAWAY-PLT'));

        $this->assertSame('standard', $rates->suggestPalletClass(1, 1200, 1200, 1400, 600));
        $this->assertSame('oversize_high', $rates->suggestPalletClass(1, 1200, 1200, 1600, 600)); // ERP_PLAN §4.7 #19
        $this->assertSame('overweight', $rates->suggestPalletClass(1, 1200, 1200, 1400, 900));    // ERP_PLAN §4.7 #19
        $this->assertSame('oversize_wide', $rates->suggestPalletClass(1, 2400, 1200, 1400, 500));
        $this->assertNull($rates->suggestPalletClass(1, 2600, 1200, 1400, 500));
    }

    public function test_fake_stock_service_reserves_partially_and_releases(): void
    {
        $stock = new FakeStockService;
        $stock->seed(1, 10, 30);

        [$line] = $stock->reserve(1, 500, [['order_line_id' => 1, 'asn_line_id' => 10, 'qty' => 40]]);
        $this->assertSame(30, $line['reserved_qty']);
        $this->assertSame(10, $line['shortfall_qty']);
        $this->assertSame(0, $stock->onHand(1, 10)['qty_available']);

        $this->assertSame(30, $stock->release(500));
        $this->assertSame(30, $stock->onHand(1, 10)['qty_available']);
        $this->assertSame(FakeStockService::DEFAULT_ON_HAND, $stock->onHand(1, 99)['qty_on_hand']);
    }

    public function test_transport_options_flag_cheapest_fastest_and_recommended(): void
    {
        $quotes = collect((new FakeTransportOptionService)->quote(7, 'final'));

        $this->assertCount(3, $quotes);
        $this->assertSame('eiz', $quotes->firstWhere('is_cheapest', true)['source']);
        $this->assertSame('eiz', $quotes->firstWhere('is_recommended', true)['source']);
        $this->assertSame('own_fleet', $quotes->firstWhere('is_fastest', true)['source']);
        $this->assertSame(7200, $quotes->firstWhere('source', 'transdirect')['customer_price_cents']);
    }

    public function test_fake_job_service_numbers_jobs_and_summarizes(): void
    {
        $jobs = new FakeJobService;

        $job = $jobs->create(1, 'container');
        $this->assertMatchesRegularExpression('/^JOB-\d{8}-0001$/', $job['job_no']);

        $summary = $jobs->summarize($job['job_id']);
        $this->assertSame(['open', 'unbilled', 'estimated'], [$summary['operational_status'], $summary['revenue_status'], $summary['cost_status']]);
        $this->assertTrue($summary['margin_is_estimate']);
    }

    public function test_order_service_and_manifest_parser_return_contract_shapes(): void
    {
        $result = (new FakeOrderService)->createFromAsn(42);
        $this->assertSame('ORD-FAKE-000042-0001', $result['orders'][0]['order_no']);
        $this->assertSame([], $result['blocked']);

        $parsed = (new FakeManifestParser)->parse('/dev/null');
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame([], $parsed['errors']);
        $this->assertSame('FAKE-MARK-01', $parsed['rows'][0]['consignment_mark']);
    }
}
