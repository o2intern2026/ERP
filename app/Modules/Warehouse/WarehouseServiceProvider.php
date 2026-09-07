<?php

namespace App\Modules\Warehouse;

use App\Modules\Warehouse\Console\ReconcileStockCommand;
use App\Modules\Warehouse\Console\SnapshotStockCommand;
use App\Modules\Warehouse\Consumers\OrderCancelledConsumer;
use App\Modules\Warehouse\Consumers\OrderConfirmedConsumer;
use App\Modules\Warehouse\Consumers\OrderReducedConsumer;
use App\Modules\Warehouse\Services\StockService;
use App\Support\Contracts\StockService as StockServiceContract;
use App\Support\Outbox\ConsumerRegistry;
use Illuminate\Support\ServiceProvider;

class WarehouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Real StockService (contracts/services.md §1) from M2; overrides FakeStockService.
        $this->app->singleton(StockServiceContract::class, StockService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'warehouse');
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        $registry = $this->app->make(ConsumerRegistry::class);
        $registry->register('order.confirmed', OrderConfirmedConsumer::class);
        $registry->register('order.cancelled', OrderCancelledConsumer::class);
        $registry->register('order.reduced', OrderReducedConsumer::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileStockCommand::class, SnapshotStockCommand::class]);
        }
    }
}
