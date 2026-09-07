<?php

namespace App\Modules\Transport;

use Illuminate\Support\ServiceProvider;

class TransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'transport');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
