<?php

namespace App\Modules\MasterData;

use Illuminate\Support\ServiceProvider;

class MasterDataServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'masterdata');
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
