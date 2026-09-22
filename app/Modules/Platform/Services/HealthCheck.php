<?php

namespace App\Modules\Platform\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CR #137 (audit ADMIN-12): is the background work alive? Production is cron only (schedule:run → outbox:dispatch every ten
 * seconds, queue:work --stop-when-empty): a stopped cron does not fail anything, events simply stay `pending`. The facts here
 * feed the admin banner (one cheap query, cached 60 s) and `php artisan erp:health` (cron / external monitor).
 */
final class HealthCheck
{
    public const CACHE_KEY = 'erp.health.banner';

    public const CACHE_SECONDS = 60;

    /**
     * @return array{pending: int, oldest_pending_minutes: ?int, failed: int, dead: int, last_snapshot_date: ?string, queue_size: int, stale_after_minutes: int, stale: bool}
     */
    public function facts(): array
    {
        $counts = DB::table('outbox_events')->selectRaw('status, count(*) as n')->whereIn('status', ['pending', 'failed', 'dead'])->groupBy('status')->pluck('n', 'status');
        $oldest = $this->oldestPendingMinutes();
        $staleAfter = $this->staleAfterMinutes();

        return [
            'pending' => (int) ($counts['pending'] ?? 0),
            'oldest_pending_minutes' => $oldest,
            'failed' => (int) ($counts['failed'] ?? 0),
            'dead' => (int) ($counts['dead'] ?? 0),
            'last_snapshot_date' => Schema::hasTable('stock_snapshots') ? DB::table('stock_snapshots')->max('snapshot_date') : null,
            'queue_size' => Schema::hasTable('queue_jobs') ? (int) DB::table('queue_jobs')->count() : 0,
            'stale_after_minutes' => $staleAfter,
            'stale' => $oldest !== null && $oldest >= $staleAfter,
        ];
    }

    /**
     * The banner's one question — "is the oldest pending event older than N minutes?" — answered from cache for 60 s so every page
     * of every admin costs one indexed query a minute, not one per request. Cleared by the dispatcher? No: a delivered backlog
     * simply stops being stale at the next refresh.
     *
     * @return array{minutes: int, pending: int}|null null when nothing is stale
     */
    public function staleBanner(): ?array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            $oldest = $this->oldestPendingMinutes();
            if ($oldest === null || $oldest < $this->staleAfterMinutes()) {
                return []; // cached as "nothing to show" — Cache::remember treats null as a miss
            }

            return ['minutes' => $oldest, 'pending' => (int) DB::table('outbox_events')->where('status', 'pending')->count()];
        }) ?: null;
    }

    public function staleAfterMinutes(): int
    {
        return max(1, (int) config('erp.outbox_stale_minutes', 10));
    }

    /** Age in whole minutes of the oldest event still pending (created_at, not available_at: a retry backoff is `failed`, not pending). */
    private function oldestPendingMinutes(): ?int
    {
        $oldest = DB::table('outbox_events')->where('status', 'pending')->min('created_at');

        return $oldest === null ? null : (int) now()->diffInMinutes($oldest, true);
    }
}
