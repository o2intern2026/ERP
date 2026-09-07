<?php

namespace App\Support\Fakes;

use App\Support\Contracts\ManifestParser;
use App\Support\Contracts\OrderService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the Fake implementations of contracts/services.md when config('erp.use_fake_services') is true
 * (env USE_FAKE_SERVICES). Tests run with it on; a seat whose dependency block is not merged yet turns it on locally.
 * Module providers bind the real implementations at their checkpoints; the flag must be off in production.
 * Retired fakes (real implementation merged): FakeJobService (M1), FakeStockService (M2).
 */
final class FakeServicesProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app['config']->get('erp.use_fake_services')) {
            return;
        }

        $this->app->singleton(OrderService::class, FakeOrderService::class);
        $this->app->singleton(TransportOptionService::class, FakeTransportOptionService::class);
        $this->app->singleton(RateService::class, FakeRateService::class);
        $this->app->singleton(ManifestParser::class, FakeManifestParser::class);
    }
}
