<?php

namespace App\Modules\Transport;

use App\Modules\Transport\Adapters\ManualCarrierAdapter;
use App\Modules\Transport\Adapters\OwnFleetCarrierAdapter;
use App\Modules\Transport\Adapters\TransdirectAdapter;
use App\Modules\Transport\Console\SyncTrackingCommand;
use App\Modules\Transport\Services\TransportOptionService;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Console\Scheduling\Schedule;
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

        $this->app->singleton(Services\ShipmentLabelService::class, fn ($app) => new Services\ShipmentLabelService(
            $app->make(Services\PackageManifest::class),
            $app->make(Services\OwnFleetLabelPdf::class),
            $app->make(DocumentService::class),
            $app->tagged('transport.carrier-adapters'),
        ));

        $this->app->singleton(Services\TrackingSyncService::class, fn ($app) => new Services\TrackingSyncService(
            $app->tagged('transport.carrier-adapters'),
            $app->make(Services\ShipmentProgressService::class),
            $app->make(ExceptionService::class),
            $app->make(OutboxPublisher::class),
        ));

        $this->app->singleton(Services\ShipmentBookingService::class, fn ($app) => new Services\ShipmentBookingService(
            $app->tagged('transport.carrier-adapters'),
            $app->make(Services\ShipmentQuoteRequestFactory::class),
            $app->make(ExceptionService::class),
            $app->make(Services\CarrierCostService::class),
            $app->make(OutboxPublisher::class),
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
        $this->commands([SyncTrackingCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('transport:sync-tracking')->everyThirtyMinutes()->withoutOverlapping();
        });
    }
}
