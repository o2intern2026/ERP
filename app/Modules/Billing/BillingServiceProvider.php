<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Console\BillStorageWeeklyCommand;
use App\Modules\Billing\Console\FlagOverdueInvoicesCommand;
use App\Modules\Billing\Consumers\BillingChargeConsumer;
use App\Modules\Billing\Consumers\PerJobInvoiceConsumer;
use App\Modules\Billing\Services\RateService;
use App\Support\Contracts\RateService as RateServiceContract;
use App\Support\Outbox\ConsumerRegistry;
use Illuminate\Support\ServiceProvider;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Real RateService (contracts/services.md §4) from the billing block; overrides FakeRateService.
        $this->app->singleton(RateServiceContract::class, RateService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'billing');
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        $registry = $this->app->make(ConsumerRegistry::class);
        foreach (BillingChargeConsumer::EVENTS as $event) {
            $registry->register($event, BillingChargeConsumer::class);
        }
        $registry->register('shipment.quote_confirmed', PerJobInvoiceConsumer::class); // after the charges: per_job service invoice draft (§7 step 4)

        if ($this->app->runningInConsole()) {
            $this->commands([BillStorageWeeklyCommand::class, FlagOverdueInvoicesCommand::class]);
        }
    }
}
