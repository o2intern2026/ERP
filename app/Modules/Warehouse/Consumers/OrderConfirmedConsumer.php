<?php

namespace App\Modules\Warehouse\Consumers;

use App\Modules\Warehouse\Events\StockReservationFailed;
use App\Modules\Warehouse\Events\StockReserved;
use App\Modules\Warehouse\Models\StockReservation;
use App\Support\Contracts\StockService;
use App\Support\Outbox\EventConsumer;
use App\Support\Outbox\OutboxPublisher;

/** order.confirmed → reserve stock → stock.reserved | stock.reservation_failed (contracts/events.md). */
final class OrderConfirmedConsumer implements EventConsumer
{
    public function __construct(private readonly StockService $stock, private readonly OutboxPublisher $outbox) {}

    public function handle(array $envelope): void
    {
        $p = $envelope['payload'];
        if (($p['order_type'] ?? 'from_stock') !== 'from_stock') {
            return; // pickup_deliver / return orders never touch stock
        }

        $lines = collect($p['lines'] ?? [])
            ->filter(fn ($l) => ! empty($l['asn_line_id']))
            ->map(fn ($l) => ['order_line_id' => (int) $l['order_line_id'], 'asn_line_id' => (int) $l['asn_line_id'], 'qty' => (int) $l['carton_qty']])
            ->values()->all();

        $result = $this->stock->reserve((int) $p['client_id'], (int) $p['order_id'], $lines);

        $reservations = StockReservation::query()->whereIn('id', collect($result)->flatMap(fn ($r) => $r['reservation_ids']))->with('stockUnit')->get()
            ->map(fn (StockReservation $r) => ['reservation_id' => $r->id, 'order_line_id' => $r->order_line_id, 'asn_line_id' => $r->stockUnit->asn_line_id, 'stock_unit_id' => $r->stock_unit_id, 'warehouse_id' => $r->stockUnit->warehouse_id, 'qty' => $r->qty])
            ->values()->all();

        $shortfalls = collect($result)->filter(fn ($r) => $r['shortfall_qty'] > 0)->values()->all();
        $base = ['order_id' => (int) $p['order_id'], 'job_id' => $envelope['job_id'], 'client_id' => (int) $p['client_id']];

        $event = $shortfalls === []
            ? new StockReserved($base + ['fully_reserved' => true, 'reservations' => $reservations], $envelope['job_id'], (int) $p['client_id'], $envelope['correlation_id'])
            : new StockReservationFailed($base + ['shortfalls' => $shortfalls, 'reservations' => $reservations], $envelope['job_id'], (int) $p['client_id'], $envelope['correlation_id']);

        $this->outbox->publish($event);
    }
}
