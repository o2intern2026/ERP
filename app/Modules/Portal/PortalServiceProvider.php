<?php

namespace App\Modules\Portal;

use Illuminate\Support\ServiceProvider;

class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'portal');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
