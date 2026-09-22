<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Services\HealthCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CR #137 (audit ADMIN-12): a stalled outbox is shown to admins on every page and reported by `erp:health` for cron / a monitor. */
class HealthCheckTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function pendingEvent(int $minutesAgo, string $status = 'pending'): void
    {
        DB::table('outbox_events')->insert([
            'event_id' => (string) Str::uuid(), 'event_name' => 'order.confirmed', 'event_version' => 1, 'payload' => '{}', 'status' => $status,
            'attempts' => 0, 'available_at' => now()->subMinutes($minutesAgo), 'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_admins_see_the_banner_only_when_the_oldest_pending_event_is_stale(): void
    {
        config(['erp.outbox_stale_minutes' => 10]);
        $admin = $this->staff();

        // Nothing pending, or pending but fresh: no banner (the cached "nothing" is cleared between checks — 60 s in production).
        $this->actingAs($admin)->get('/jobs')->assertOk()->assertDontSee(__('platform.health.banner_title'));
        Cache::forget(HealthCheck::CACHE_KEY);
        $this->pendingEvent(3);
        $this->actingAs($admin)->get('/jobs')->assertOk()->assertDontSee(__('platform.health.banner_title'));

        // A pending event older than N minutes: red banner with its age and the pending count, linking the monitor — on every page.
        Cache::forget(HealthCheck::CACHE_KEY);
        $this->pendingEvent(23);
        $this->actingAs($admin)->get('/jobs')->assertOk()
            ->assertSee(__('platform.health.banner_title'))
            ->assertSee(__('platform.health.banner', ['minutes' => 23, 'pending' => 2]))
            ->assertSee(route('platform.integration.index', ['status' => 'pending']));
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee(__('platform.health.banner_title'));

        // Delivered or failed (retry backoff) rows are not "pending"; other roles never see the banner.
        $this->actingAs($this->staff('finance'))->get('/jobs')->assertOk()->assertDontSee(__('platform.health.banner_title'));
        DB::table('outbox_events')->update(['status' => 'published']);
        Cache::forget(HealthCheck::CACHE_KEY);
        $this->pendingEvent(40, 'failed');
        $this->actingAs($admin)->get('/jobs')->assertOk()->assertDontSee(__('platform.health.banner_title'));
    }

    public function test_the_banner_is_cached_for_a_minute(): void
    {
        config(['erp.outbox_stale_minutes' => 10]);
        $admin = $this->staff();
        $this->pendingEvent(30);
        $this->actingAs($admin)->get('/jobs')->assertSee(__('platform.health.banner_title'));

        DB::table('outbox_events')->update(['status' => 'published']); // cron caught up …
        $this->actingAs($admin)->get('/jobs')->assertSee(__('platform.health.banner_title')); // … the banner follows within 60 s
        Cache::forget(HealthCheck::CACHE_KEY);
        $this->actingAs($admin)->get('/jobs')->assertDontSee(__('platform.health.banner_title'));
    }

    public function test_the_health_command_prints_the_facts_and_exits_non_zero_when_stale(): void
    {
        config(['erp.outbox_stale_minutes' => 10]);
        $this->stockedAsn($this->client(), $this->warehouse(), [['mark' => 'HLTH', 'cartons' => 2]]);
        DB::table('outbox_events')->update(['status' => 'published']); // the fixture's own events are delivered
        $this->artisan('stock:snapshot')->assertSuccessful(); // today's storage snapshot → "last stock snapshot"

        $this->artisan('erp:health')->assertSuccessful()
            ->expectsOutputToContain('pending events:        0')
            ->expectsOutputToContain('last stock snapshot:   '.today()->toDateString())
            ->expectsOutputToContain('STATUS: OK');

        $this->pendingEvent(2);
        $this->pendingEvent(45);
        $this->pendingEvent(5, 'dead');
        $this->artisan('erp:health')->assertFailed()
            ->expectsOutputToContain('pending events:        2')
            ->expectsOutputToContain('oldest pending age:    45 min (stale after 10)')
            ->expectsOutputToContain('dead (needs a human):  1')
            ->expectsOutputToContain('STATUS: STALE');

        $facts = app(HealthCheck::class)->facts();
        $this->assertSame([2, 45, 0, 1, today()->toDateString(), 0, true], [$facts['pending'], $facts['oldest_pending_minutes'], $facts['failed'], $facts['dead'], $facts['last_snapshot_date'], $facts['queue_size'], $facts['stale']]);
        $this->artisan('erp:health --json')->assertFailed()->expectsOutputToContain('"oldest_pending_minutes":45');
    }
}
