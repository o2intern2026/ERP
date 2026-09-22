<?php

namespace App\Modules\Warehouse\Consumers;

use App\Modules\Warehouse\Events\StockReleased;
use App\Modules\Warehouse\Models\StockReservation;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Contracts\StockService;
use App\Support\Outbox\EventConsumer;
use App\Support\Outbox\OutboxPublisher;

/**
 * order.cancelled → pick tasks nobody started are cancelled (their waves re-checked), then every active reservation is released → stock.released.
 * A pick task already in progress stays for the supervisor's 关闭任务 on the wave page (audit 2026-09-22 OUTBOUND-02). No order note is
 * written back: Orders exposes no note hook in contracts/services.md, and Warehouse never writes another module's tables.
 */
final class OrderCancelledConsumer implements EventConsumer
{
    public function __construct(private readonly StockService $stock, private readonly OutboxPublisher $outbox, private readonly OutboundService $outbound) {}

    public function handle(array $envelope): void
    {
        $p = $envelope['payload'];
        $orderId = (int) $p['order_id'];
        $this->outbound->cancelUnstartedPickTasks($orderId);

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
