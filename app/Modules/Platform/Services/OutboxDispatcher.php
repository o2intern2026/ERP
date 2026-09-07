<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\ConsumedEvent;
use App\Modules\Platform\Models\OutboxEvent;
use App\Support\Contracts\ExceptionService;
use App\Support\Outbox\ConsumerRegistry;
use App\Support\Outbox\EventConsumer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers outbox rows to registered consumers (A31). Runs from cron via `outbox:dispatch` (scheduler, every minute);
 * no long-running process. Each consumer runs in its own savepoint together with its consumed_events row, so a
 * consumer is applied at most once per event. Failures back off 1 m → 5 m → 30 m → 2 h → 12 h; after MAX_ATTEMPTS the
 * event is `dead`, an `integration_failed` exception is raised and an alert is logged (contracts/events.md rules).
 */
final class OutboxDispatcher
{
    public const MAX_ATTEMPTS = 5;

    /** @var list<int> minutes to wait before attempt n+1 */
    public const BACKOFF_MINUTES = [1, 5, 30, 120, 720];

    public function __construct(
        private readonly ConsumerRegistry $registry,
        private readonly ExceptionService $exceptions,
    ) {}

    /**
     * @return array{published:int, failed:int, dead:int}
     */
    public function dispatchDue(int $limit = 100): array
    {
        $counts = ['published' => 0, 'failed' => 0, 'dead' => 0];

        $ids = OutboxEvent::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $result = $this->dispatchOne((int) $id);
            if (isset($counts[$result])) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    /** @return string resulting status: published | failed | dead | skipped */
    public function dispatchOne(int $outboxEventId): string
    {
        return DB::transaction(function () use ($outboxEventId): string {
            /** @var OutboxEvent|null $event */
            $event = OutboxEvent::query()->lockForUpdate()->find($outboxEventId);

            if ($event === null || ! in_array($event->status, ['pending', 'failed'], true)) {
                return 'skipped';
            }

            $envelope = $event->envelope();
            $errors = [];

            foreach ($this->registry->for($event->event_name) as $consumerClass) {
                if ($this->alreadyConsumed($event->event_id, $consumerClass)) {
                    continue;
                }

                try {
                    DB::transaction(function () use ($consumerClass, $envelope, $event): void {
                        /** @var EventConsumer $consumer */
                        $consumer = app($consumerClass);
                        $consumer->handle($envelope);

                        ConsumedEvent::query()->create([
                            'event_id' => $event->event_id,
                            'consumer' => $consumerClass,
                            'consumed_at' => now(),
                        ]);
                    });
                } catch (Throwable $e) {
                    $errors[] = $consumerClass.': '.$e->getMessage();
                    Log::warning('outbox: consumer failed', ['event' => $event->event_name, 'event_id' => $event->event_id, 'consumer' => $consumerClass, 'error' => $e->getMessage()]);
                }
            }

            if ($errors === []) {
                $event->update(['status' => 'published', 'published_at' => now(), 'last_error' => null]);

                return 'published';
            }

            $attempts = $event->attempts + 1;
            $lastError = implode("\n", $errors);

            if ($attempts >= self::MAX_ATTEMPTS) {
                $event->update(['status' => 'dead', 'attempts' => $attempts, 'last_error' => $lastError]);
                $this->alertDead($event, $lastError);

                return 'dead';
            }

            $event->update([
                'status' => 'failed',
                'attempts' => $attempts,
                'last_error' => $lastError,
                'available_at' => now()->addMinutes(self::BACKOFF_MINUTES[$attempts - 1] ?? end(self::BACKOFF_MINUTES)),
            ]);

            return 'failed';
        });
    }

    /** Manual retry from the Integration Monitor: back to pending, attempts reset, delivered on the next run. */
    public function retry(int $outboxEventId): void
    {
        OutboxEvent::query()->whereKey($outboxEventId)->whereIn('status', ['failed', 'dead'])->update([
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ]);
    }

    private function alreadyConsumed(string $eventId, string $consumerClass): bool
    {
        return ConsumedEvent::query()->where('event_id', $eventId)->where('consumer', $consumerClass)->exists();
    }

    /** Alert + integration_failed exception. Must never throw: a broken job/client reference must not stop the event from being marked dead. */
    private function alertDead(OutboxEvent $event, string $lastError): void
    {
        Log::alert('outbox: event dead after '.self::MAX_ATTEMPTS.' attempts', ['event' => $event->event_name, 'event_id' => $event->event_id, 'error' => $lastError]);

        $attributes = [
            'source_type' => 'outbox_event',
            'source_id' => $event->id,
            'message' => "{$event->event_name} ({$event->event_id}) failed ".self::MAX_ATTEMPTS.' times: '.mb_substr($lastError, 0, 500),
        ];

        try {
            $this->exceptions->raise('integration_failed', 'platform', $attributes + ['job_id' => $event->job_id, 'client_id' => $event->client_id]);
        } catch (Throwable) {
            // The event may reference a job or client this database does not know: record the failure unlinked.
            try {
                $this->exceptions->raise('integration_failed', 'platform', $attributes);
            } catch (Throwable $inner) {
                Log::error('outbox: could not raise integration_failed exception', ['error' => $inner->getMessage()]);
            }
        }
    }
}
