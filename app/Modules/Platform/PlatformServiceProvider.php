<?php

namespace App\Modules\Platform;

use App\Modules\Platform\Console\DispatchOutboxCommand;
use App\Modules\Platform\Services\DatabaseOutboxPublisher;
use App\Modules\Platform\Services\DocumentService;
use App\Modules\Platform\Services\ExceptionService;
use App\Modules\Platform\Services\JobService;
use App\Support\Contracts\DocumentService as DocumentServiceContract;
use App\Support\Contracts\ExceptionService as ExceptionServiceContract;
use App\Support\Contracts\JobService as JobServiceContract;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Real implementations of contracts/services.md §5–§7 and the transactional outbox (M1: A27, A31).
        $this->app->singleton(JobServiceContract::class, JobService::class);
        $this->app->singleton(ExceptionServiceContract::class, ExceptionService::class);
        $this->app->singleton(DocumentServiceContract::class, DocumentService::class);
        $this->app->singleton(OutboxPublisher::class, DatabaseOutboxPublisher::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'platform');
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchOutboxCommand::class]);
        }
    }
}
