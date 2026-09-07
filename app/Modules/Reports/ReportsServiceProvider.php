<?php

namespace App\Modules\Reports;

use Illuminate\Support\ServiceProvider;

class ReportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'reports');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
