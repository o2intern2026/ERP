<?php

namespace App\Modules\Platform\Consumers;

use App\Modules\Platform\Models\WebhookDelivery;
use App\Modules\Platform\Models\WebhookEndpoint;
use App\Modules\Platform\Services\WebhookSender;
use App\Support\Outbox\EventConsumer;

/**
 * A23: offers every outbox event to the active endpoints subscribed to it. Never throws — each endpoint gets its own
 * delivery row and its own retry schedule (WebhookSender / `webhooks:retry`), so the event itself is published once.
 */
final class WebhookConsumer implements EventConsumer
{
    public function __construct(private readonly WebhookSender $sender) {}

    public function handle(array $envelope): void
    {
        $endpoints = WebhookEndpoint::query()->where('active', true)->get()->filter(fn (WebhookEndpoint $e) => $e->wants($envelope['event_name']));

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::query()->firstOrCreate(
                ['endpoint_id' => $endpoint->id, 'event_id' => $envelope['event_id']],
                ['event_name' => $envelope['event_name'], 'status' => 'pending', 'attempts' => 0, 'created_at' => now()],
            );
            if ($delivery->status === 'delivered') {
                continue;
            }
            $this->sender->send($endpoint, $delivery, $envelope);
        }
    }
}
