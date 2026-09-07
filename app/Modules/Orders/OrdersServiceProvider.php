<?php

namespace App\Modules\Orders;

use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'orders');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
