<?php

namespace App\Modules\Warehouse\Consumers;

use App\Modules\Warehouse\Events\StockReleased;
use App\Modules\Warehouse\Models\StockReservation;
use App\Support\Contracts\StockService;
use App\Support\Outbox\EventConsumer;
use App\Support\Outbox\OutboxPublisher;

/** order.reduced → release the reduced lines' reservations and re-reserve the new quantity → stock.released. */
final class OrderReducedConsumer implements EventConsumer
{
    public function __construct(private readonly StockService $stock, private readonly OutboxPublisher $outbox) {}

    public function handle(array $envelope): void
    {
        $p = $envelope['payload'];
        $orderId = (int) $p['order_id'];

        foreach ($p['lines'] ?? [] as $line) {
            $ids = StockReservation::query()->where('order_id', $orderId)->where('order_line_id', (int) $line['order_line_id'])->where('status', 'active')->pluck('id')->all();
            $released = $this->stock->release($orderId, (int) $line['order_line_id'], 'order_reduced');
            if ((int) $line['new_qty'] > 0) {
                $this->stock->reserve((int) $p['client_id'], $orderId, [['order_line_id' => (int) $line['order_line_id'], 'asn_line_id' => (int) $line['asn_line_id'], 'qty' => (int) $line['new_qty']]]);
            }
            if ($released > 0) {
                $this->outbox->publish(new StockReleased([
                    'order_id' => $orderId, 'order_line_id' => (int) $line['order_line_id'], 'job_id' => $envelope['job_id'], 'client_id' => (int) $p['client_id'],
                    'qty' => $released - (int) $line['new_qty'] > 0 ? $released - (int) $line['new_qty'] : $released, 'reason' => 'order_reduced', 'reservation_ids' => $ids, 'released_at' => now()->toIso8601String(),
                ], $envelope['job_id'], (int) $p['client_id'], $envelope['correlation_id']));
            }
        }
    }
}
