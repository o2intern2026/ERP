<?php

namespace App\Modules\MasterData;

use App\Modules\MasterData\Models\Client;
use App\Support\Search\SearchRegistry;
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

        // Same roles as routes.php (admin | customer_service | finance): other staff never get a 客户 hit they cannot open, and the
        // client's billing terms are not shown in the hit (audit 2026-09-10).
        $this->app->make(SearchRegistry::class)->register('masterdata', fn (string $q): array => Client::query()
            ->where(fn ($w) => $w->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%"))->limit(10)->get()
            ->map(fn (Client $c) => ['type' => 'client', 'label' => $c->code.' · '.$c->name, 'url' => route('masterdata.clients.edit', $c), 'meta' => __('masterdata.statuses.'.$c->status)])->all(),
            ['admin', 'customer_service', 'finance']);
    }
}
