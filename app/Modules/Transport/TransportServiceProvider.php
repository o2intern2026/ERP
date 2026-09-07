<?php

namespace App\Modules\Transport;

use App\Modules\Transport\Adapters\ManualCarrierAdapter;
use App\Modules\Transport\Adapters\OwnFleetCarrierAdapter;
use App\Modules\Transport\Adapters\TransdirectAdapter;
use App\Modules\Transport\Console\SyncTrackingCommand;
use App\Modules\Transport\Consumers\OrderConfirmedConsumer;
use App\Modules\Transport\Consumers\OutboundDispatchedConsumer;
use App\Modules\Transport\Consumers\OutboundPackedConsumer;
use App\Modules\Transport\Services\TransportOptionService;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use App\Support\Outbox\ConsumerRegistry;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class TransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // services.transdirect lives in config/services.php (env() is only read there — config:cache safe). Integrator edit, CHANGE_REQUESTS #45.
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

        // Real since M5 (integration by C, 2026-09-08): FakeTransportOptionService is retired, so the contract is always the real service.
        $this->app->singleton(
            TransportOptionServiceContract::class,
            fn ($app) => $app->make(TransportOptionService::class),
        );
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'transport');
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        $registry = $this->app->make(ConsumerRegistry::class);
        $registry->register('order.confirmed', OrderConfirmedConsumer::class);
        $registry->register('outbound.packed', OutboundPackedConsumer::class);
        $registry->register('outbound.dispatched', OutboundDispatchedConsumer::class);

        $this->commands([SyncTrackingCommand::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('transport:sync-tracking')->everyThirtyMinutes()->withoutOverlapping();
        });
    }
}
