<?php

namespace App\Modules\Warehouse\Consumers;

use App\Modules\Warehouse\Events\StockReleased;
use App\Modules\Warehouse\Models\StockReservation;
use App\Support\Contracts\StockService;
use App\Support\Outbox\EventConsumer;
use App\Support\Outbox\OutboxPublisher;

/** order.cancelled → release every active reservation → stock.released. */
final class OrderCancelledConsumer implements EventConsumer
{
    public function __construct(private readonly StockService $stock, private readonly OutboxPublisher $outbox) {}

    public function handle(array $envelope): void
    {
        $p = $envelope['payload'];
        $orderId = (int) $p['order_id'];
        $ids = StockReservation::query()->where('order_id', $orderId)->where('status', 'active')->pluck('id')->all();

        $qty = $this->stock->release($orderId, null, 'order_cancelled');
        if ($qty === 0) {
            return;
        }

        $this->outbox->publish(new StockReleased([
            'order_id' => $orderId, 'order_line_id' => null, 'job_id' => $envelope['job_id'], 'client_id' => $p['client_id'] ?? $envelope['client_id'],
            'qty' => $qty, 'reason' => 'order_cancelled', 'reservation_ids' => $ids, 'released_at' => now()->toIso8601String(),
        ], $envelope['job_id'], $envelope['client_id'], $envelope['correlation_id']));
    }
}
