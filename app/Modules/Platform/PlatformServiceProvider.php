<?php

namespace App\Modules\Platform;

use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'platform');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
