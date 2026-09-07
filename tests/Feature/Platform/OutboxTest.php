<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Support\Contracts\JobService;
use App\Support\Outbox\ConsumerRegistry;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CreatesUsers;
use Tests\Support\Outbox\FailingConsumer;
use Tests\Support\Outbox\RecordingConsumer;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** A31: transactional outbox, idempotent consumers, backoff, dead-letter + alert, manual retry, integration monitor. */
class OutboxTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingConsumer::$seen = [];
    }

    public function test_publish_writes_a_pending_row_inside_the_business_transaction(): void
    {
        $event = new TestEvent(['order_id' => 7], 'order.confirmed', jobId: 3, clientId: 2);

        DB::transaction(fn () => app(OutboxPublisher::class)->publish($event));

        $this->assertDatabaseHas('outbox_events', ['event_id' => $event->eventId, 'event_name' => 'order.confirmed', 'status' => 'pending', 'job_id' => 3, 'client_id' => 2, 'attempts' => 0]);
        $this->assertSame(['order_id' => 7], OutboxEvent::query()->firstOrFail()->payload);
    }

    public function test_a_rolled_back_business_write_takes_its_event_with_it(): void
    {
        $event = new TestEvent;

        try {
            DB::transaction(function () use ($event): void {
                app(OutboxPublisher::class)->publish($event);
                throw new RuntimeException('business write failed');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseMissing('outbox_events', ['event_id' => $event->eventId]);
    }

    public function test_dispatch_delivers_each_event_to_each_consumer_exactly_once(): void
    {
        app(ConsumerRegistry::class)->register('test.event', RecordingConsumer::class);
        $event = new TestEvent(['n' => 1]);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish($event));

        $counts = app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(1, $counts['published']);
        $this->assertCount(1, RecordingConsumer::$seen);
        $this->assertSame($event->eventId, RecordingConsumer::$seen[0]['event_id']);
        $this->assertSame(['n' => 1], RecordingConsumer::$seen[0]['payload']);
        $this->assertDatabaseHas('outbox_events', ['event_id' => $event->eventId, 'status' => 'published']);
        $this->assertDatabaseHas('consumed_events', ['event_id' => $event->eventId, 'consumer' => RecordingConsumer::class]);

        // A redelivery (e.g. after a crash between consumer and status update) must not run the consumer again.
        OutboxEvent::query()->update(['status' => 'pending']);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertCount(1, RecordingConsumer::$seen);
        $this->assertDatabaseHas('outbox_events', ['event_id' => $event->eventId, 'status' => 'published']);
    }

    public function test_a_failing_consumer_backs_off_and_does_not_block_the_others(): void
    {
        $registry = app(ConsumerRegistry::class);
        $registry->register('test.event', RecordingConsumer::class);
        $registry->register('test.event', FailingConsumer::class);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent));

        $counts = app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(1, $counts['failed']);
        $event = OutboxEvent::query()->firstOrFail();
        $this->assertSame('failed', $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertStringContainsString('boom', $event->last_error);
        $this->assertTrue($event->available_at->greaterThan(now()->addSeconds(30)));
        $this->assertCount(1, RecordingConsumer::$seen);

        // Not due yet: nothing happens. When due again, only the failing consumer is retried.
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(1, $event->fresh()->attempts);

        $event->update(['available_at' => now()->subSecond()]);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(2, $event->fresh()->attempts);
        $this->assertCount(1, RecordingConsumer::$seen);
    }

    public function test_after_five_failures_the_event_is_dead_and_an_integration_exception_is_raised(): void
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'container');
        app(ConsumerRegistry::class)->register('test.event', FailingConsumer::class);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([], 'test.event', jobId: $job['job_id'], clientId: $client->id)));
        OutboxEvent::query()->update(['attempts' => OutboxDispatcher::MAX_ATTEMPTS - 1]);

        $counts = app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(1, $counts['dead']);
        $event = OutboxEvent::query()->firstOrFail();
        $this->assertSame('dead', $event->status);
        $this->assertDatabaseHas('exceptions', ['type' => 'integration_failed', 'source_module' => 'platform', 'source_type' => 'outbox_event', 'source_id' => $event->id, 'job_id' => $job['job_id'], 'client_id' => $client->id, 'status' => 'open']);

        // Manual retry from the monitor puts it back in the queue with a fresh attempt budget.
        app(OutboxDispatcher::class)->retry($event->id);
        $this->assertDatabaseHas('outbox_events', ['id' => $event->id, 'status' => 'pending', 'attempts' => 0]);
    }

    public function test_a_dead_event_with_an_unknown_job_still_raises_an_exception(): void
    {
        app(ConsumerRegistry::class)->register('test.event', FailingConsumer::class);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([], 'test.event', jobId: 999999, clientId: 999999)));
        OutboxEvent::query()->update(['attempts' => OutboxDispatcher::MAX_ATTEMPTS - 1]);

        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertDatabaseHas('outbox_events', ['status' => 'dead']);
        $this->assertDatabaseHas('exceptions', ['type' => 'integration_failed', 'source_type' => 'outbox_event', 'job_id' => null, 'client_id' => null]);
    }

    public function test_integration_monitor_lists_events_and_retries_from_the_page(): void
    {
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([], 'asn.putaway_completed')));
        $event = OutboxEvent::query()->firstOrFail();
        $event->update(['status' => 'dead', 'attempts' => 5, 'last_error' => 'boom']);

        $admin = $this->staff();
        $this->actingAs($admin)->get('/admin/integration')->assertOk()->assertSee('asn.putaway_completed')->assertSee(__('platform.integration.retry'));
        $this->actingAs($admin)->post("/admin/integration/{$event->id}/retry")->assertRedirect();
        $this->assertSame('pending', $event->fresh()->status);

        $this->actingAs($this->staff('finance'))->get('/admin/integration')->assertForbidden();
    }

    public function test_the_cron_command_runs(): void
    {
        $this->artisan('outbox:dispatch')->assertSuccessful();
    }
}
