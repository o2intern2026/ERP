<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\WebhookDelivery;
use App\Modules\Platform\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * A23: one signed POST per endpoint × event. Failures are recorded on the delivery row with a backoff and retried by
 * `webhooks:retry` — independent of the outbox, so one slow customer endpoint never blocks the event for everyone else.
 */
final class WebhookSender
{
    public const MAX_ATTEMPTS = 5;

    /** @var list<int> minutes */
    public const BACKOFF_MINUTES = [1, 5, 30, 120, 720];

    /** @param array<string, mixed> $envelope */
    public function send(WebhookEndpoint $endpoint, WebhookDelivery $delivery, array $envelope): bool
    {
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $attempts = $delivery->attempts + 1;
        $code = null;

        try {
            $response = Http::timeout(10)->withBody($body, 'application/json')->withHeaders([
                'X-ERP-Event' => $envelope['event_name'],
                'X-ERP-Event-Id' => $envelope['event_id'],
                'X-ERP-Signature' => 'sha256='.hash_hmac('sha256', $body, $endpoint->secret),
            ])->post($endpoint->url);
            $code = $response->status();

            if ($response->failed()) {
                throw new RuntimeException("HTTP {$code}");
            }

            $delivery->update(['status' => 'delivered', 'attempts' => $attempts, 'response_code' => $code, 'delivered_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);

            return true;
        } catch (Throwable $e) {
            $dead = $attempts >= self::MAX_ATTEMPTS;
            $delivery->update([
                'status' => $dead ? 'dead' : 'failed',
                'attempts' => $attempts,
                'response_code' => $code,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                'next_attempt_at' => $dead ? null : now()->addMinutes(self::BACKOFF_MINUTES[$attempts - 1] ?? end(self::BACKOFF_MINUTES)),
            ]);
            Log::warning('webhook: delivery failed', ['endpoint' => $endpoint->name, 'event' => $envelope['event_name'], 'attempt' => $attempts, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
