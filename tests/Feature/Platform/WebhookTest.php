<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\WebhookDelivery;
use App\Modules\Platform\Models\WebhookEndpoint;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesUsers;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** A23: signed pushes for subscribed events, per-endpoint delivery records, retries only re-post to failed endpoints. */
class WebhookTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_events_are_pushed_with_a_valid_signature_and_failures_retry_selectively(): void
    {
        Http::fake(['https://ok.example.test/*' => Http::response(['ok' => true], 200), 'https://down.example.test/*' => Http::response('nope', 503)]);
        $ok = WebhookEndpoint::query()->create(['name' => 'ok', 'url' => 'https://ok.example.test/hook', 'secret' => 'shh-secret', 'events' => ['*'], 'active' => true]);
        WebhookEndpoint::query()->create(['name' => 'down', 'url' => 'https://down.example.test/hook', 'secret' => 's2', 'events' => ['asn.putaway_completed'], 'active' => true]);
        WebhookEndpoint::query()->create(['name' => 'other', 'url' => 'https://ok.example.test/other', 'secret' => 's3', 'events' => ['order.confirmed'], 'active' => true]);
        WebhookEndpoint::query()->create(['name' => 'off', 'url' => 'https://ok.example.test/off', 'secret' => 's4', 'events' => ['*'], 'active' => false]);

        $event = new TestEvent(['asn_id' => 1, 'pallet_count' => 3], 'asn.putaway_completed', jobId: 7, clientId: 2);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish($event));

        $counts = app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(1, $counts['published']); // the event is published once; the "down" endpoint gets its own retry schedule
        Http::assertSentCount(2); // ok + down; "other" is not subscribed, "off" is inactive
        Http::assertSent(fn (Request $request) => $request->url() === 'https://ok.example.test/hook'
            && $request->header('X-ERP-Event')[0] === 'asn.putaway_completed'
            && $request->header('X-ERP-Event-Id')[0] === $event->eventId
            && $request->header('X-ERP-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 'shh-secret')
            && json_decode($request->body(), true)['payload']['pallet_count'] === 3
            && json_decode($request->body(), true)['job_id'] === 7);
        $this->assertDatabaseHas('webhook_deliveries', ['endpoint_id' => $ok->id, 'event_id' => $event->eventId, 'status' => 'delivered', 'response_code' => 200]);
        $this->assertDatabaseHas('webhook_deliveries', ['event_name' => 'asn.putaway_completed', 'status' => 'failed', 'response_code' => 503]);

        // Retry: not due yet → nothing; once due, only the failed endpoint is posted again.
        $this->artisan('webhooks:retry')->assertSuccessful();
        Http::assertSentCount(2);
        WebhookDelivery::query()->where('status', 'failed')->update(['next_attempt_at' => now()->subMinute()]);
        $this->artisan('webhooks:retry')->assertSuccessful();
        Http::assertSentCount(3);
        $this->assertSame(2, WebhookDelivery::query()->where('status', 'failed')->value('attempts'));
        $this->assertSame(1, WebhookDelivery::query()->where('endpoint_id', $ok->id)->count());
    }

    public function test_admin_manages_endpoints_from_the_page(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->post('/admin/webhooks', ['name' => 'BI', 'url' => 'https://bi.example.test/erp', 'events' => 'asn.putaway_completed, task.completed'])->assertRedirect();
        $endpoint = WebhookEndpoint::query()->firstOrFail();
        $this->assertSame(['asn.putaway_completed', 'task.completed'], $endpoint->events);
        $this->assertSame(48, strlen($endpoint->secret));

        $this->actingAs($admin)->get('/admin/webhooks')->assertOk()->assertSee('bi.example.test')->assertSee($endpoint->secret);
        $this->actingAs($admin)->post("/admin/webhooks/{$endpoint->id}/toggle")->assertRedirect();
        $this->assertFalse($endpoint->fresh()->active);
        $this->actingAs($admin)->post('/admin/webhooks', ['name' => 'bad', 'url' => 'http://insecure.example.test/x', 'events' => '*'])->assertSessionHasErrors('url');
        $this->actingAs($admin)->delete("/admin/webhooks/{$endpoint->id}")->assertRedirect();
        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $endpoint->id]);
        $this->actingAs($this->staff('finance'))->get('/admin/webhooks')->assertForbidden();
    }
}
