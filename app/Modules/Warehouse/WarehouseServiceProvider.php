<?php

namespace App\Modules\Warehouse;

use Illuminate\Support\ServiceProvider;

class WarehouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'warehouse');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
