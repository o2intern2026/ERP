<?php

namespace App\Modules\Platform;

use App\Modules\Platform\Console\DispatchOutboxCommand;
use App\Modules\Platform\Console\RetryWebhooksCommand;
use App\Modules\Platform\Consumers\JobCostConsumer;
use App\Modules\Platform\Consumers\JobRevenueConsumer;
use App\Modules\Platform\Consumers\WebhookConsumer;
use App\Modules\Platform\Models\Job;
use App\Modules\Platform\Services\DatabaseOutboxPublisher;
use App\Modules\Platform\Services\DocumentService;
use App\Modules\Platform\Services\ExceptionService;
use App\Modules\Platform\Services\JobService;
use App\Support\Contracts\DocumentService as DocumentServiceContract;
use App\Support\Contracts\ExceptionService as ExceptionServiceContract;
use App\Support\Contracts\JobService as JobServiceContract;
use App\Support\Outbox\ConsumerRegistry;
use App\Support\Outbox\OutboxPublisher;
use App\Support\Search\SearchRegistry;
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

        // A23: every event may be pushed to registered webhook endpoints. A30: Jobs are searchable by number / reference.
        $registry = $this->app->make(ConsumerRegistry::class);
        $registry->register(ConsumerRegistry::WILDCARD, WebhookConsumer::class);
        $registry->register('shipment.booked', JobCostConsumer::class);      // Job cost estimated (events.md matrix)
        $registry->register('delivery.pod_captured', JobCostConsumer::class); // Job actual cost / confirmed
        $registry->register('invoice.issued', JobRevenueConsumer::class);     // Job revenue invoiced
        $this->app->make(SearchRegistry::class)->register('platform', fn (string $q): array => Job::query()->with('client')
            ->where(fn ($w) => $w->where('job_no', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%"))
            ->limit(20)->get()
            ->map(fn (Job $j) => ['type' => 'job', 'label' => $j->job_no, 'url' => route('platform.jobs.show', $j), 'meta' => $j->client->name.' · '.$j->reference])->all());

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchOutboxCommand::class, RetryWebhooksCommand::class]);
        }
    }
}
