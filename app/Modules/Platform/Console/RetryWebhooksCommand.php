<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Models\WebhookDelivery;
use App\Modules\Platform\Services\WebhookSender;
use Illuminate\Console\Command;

/** Cron (every five minutes): re-send failed webhook deliveries whose backoff has elapsed. */
final class RetryWebhooksCommand extends Command
{
    protected $signature = 'webhooks:retry {--limit=200}';

    protected $description = 'Retry failed webhook deliveries that are due (A23)';

    public function handle(WebhookSender $sender): int
    {
        $due = WebhookDelivery::query()->with('endpoint')->where('status', 'failed')->where('next_attempt_at', '<=', now())->orderBy('id')->limit((int) $this->option('limit'))->get();
        $sent = 0;

        foreach ($due as $delivery) {
            $event = OutboxEvent::query()->where('event_id', $delivery->event_id)->first();
            if ($delivery->endpoint === null || ! $delivery->endpoint->active || $event === null) {
                $delivery->update(['status' => 'dead', 'last_error' => 'endpoint inactive or event missing', 'next_attempt_at' => null]);

                continue;
            }
            $sent += $sender->send($delivery->endpoint, $delivery, $event->envelope()) ? 1 : 0;
        }

        $this->info(sprintf('webhooks:retry — %d due, %d delivered', $due->count(), $sent));

        return self::SUCCESS;
    }
}
