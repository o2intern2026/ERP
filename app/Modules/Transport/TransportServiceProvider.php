<?php

namespace App\Modules\Transport;

use App\Modules\Transport\Adapters\ManualCarrierAdapter;
use App\Modules\Transport\Adapters\OwnFleetCarrierAdapter;
use App\Modules\Transport\Adapters\TransdirectAdapter;
use App\Modules\Transport\Services\TransportOptionService;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use Illuminate\Support\ServiceProvider;

class TransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set('services.transdirect', array_replace([
            'api_key' => env('TRANSDIRECT_API_KEY'),
            'base_url' => env('TRANSDIRECT_BASE_URL', 'https://www.transdirect.com.au/api'),
        ], config('services.transdirect', [])));

        $this->app->singleton(ManualCarrierAdapter::class);
        $this->app->singleton(OwnFleetCarrierAdapter::class);
        $this->app->singleton(TransdirectAdapter::class);
        $this->app->tag([
            ManualCarrierAdapter::class,
            OwnFleetCarrierAdapter::class,
            TransdirectAdapter::class,
        ], 'transport.carrier-adapters');

        $this->app->singleton(TransportOptionService::class, fn ($app) => new TransportOptionService(
            $app->tagged('transport.carrier-adapters'),
            $app->make(Services\ShipmentQuoteRequestFactory::class),
            $app->make(RateService::class),
            $app->make(ExceptionService::class),
            $app->make(Services\QuoteSelectionService::class),
        ));

        if (! config('erp.use_fake_services')) {
            $this->app->singleton(
                TransportOptionServiceContract::class,
                fn ($app) => $app->make(TransportOptionService::class),
            );
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'transport');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
