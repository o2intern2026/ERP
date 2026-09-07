<?php

namespace App\Modules\Orders;

use App\Modules\Orders\Services\AsnOrderService;
use App\Modules\Orders\Services\SpreadsheetManifestParser;
use App\Support\Contracts\ManifestParser;
use App\Support\Contracts\OrderService;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CI keeps contract Fakes enabled until M3 integration; production always receives the real A4 services.
        if (! config('erp.use_fake_services')) {
            $this->app->singleton(ManifestParser::class, SpreadsheetManifestParser::class);
            $this->app->singleton(OrderService::class, AsnOrderService::class);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'orders');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
