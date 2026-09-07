<?php

namespace App\Support\Fakes;

use Illuminate\Support\ServiceProvider;

/**
 * Bound the Fake implementations of contracts/services.md while config('erp.use_fake_services') was true
 * (env USE_FAKE_SERVICES). Every Fake is now retired — the module providers bind the real services at their checkpoints:
 * FakeJobService (M1, Platform), FakeStockService (M2, Warehouse), FakeOrderService + FakeManifestParser (M3, Orders),
 * FakeTransportOptionService (M5, Transport), FakeRateService (M6, Billing). The Fake classes stay for unit tests and as
 * executable documentation of the contract shapes (tests/Feature/Platform/FakeServicesTest.php); the flag is kept so a
 * seat can still bind one locally while experimenting, and it must be false in production.
 */
final class FakeServicesProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app['config']->get('erp.use_fake_services')) {
            return;
        }

        // Nothing left to bind: all five contracts have their real implementation merged.
    }
}
