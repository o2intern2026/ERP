<?php

namespace App\Modules\Warehouse\Consumers;

use App\Modules\Warehouse\Events\StockReservationFailed;
use App\Modules\Warehouse\Events\StockReserved;
use App\Modules\Warehouse\Models\StockReservation;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\StockService;
use App\Support\Outbox\EventConsumer;
use App\Support\Outbox\OutboxPublisher;

/** order.confirmed → reserve stock → stock.reserved | stock.reservation_failed (contracts/events.md). */
final class OrderConfirmedConsumer implements EventConsumer
{
    public function __construct(private readonly StockService $stock, private readonly OutboxPublisher $outbox, private readonly ExceptionService $exceptions) {}

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

        // Tester feedback 2026-09-10 (item 4C): a shortfall used to be invisible — the order simply never reached 待释放. Now it is a
        // 缺货 exception for the Coordinator queue (auto-resolved by FulfilmentService once a later putaway fills the backorder).
        if ($shortfalls !== []) {
            $this->exceptions->raise('stock_shortage', 'warehouse', $base + [
                'source_type' => 'order', 'source_id' => (int) $p['order_id'],
                'message' => __('warehouse.outbound.shortage_exception', ['order_no' => $p['order_no'] ?? ('#'.$p['order_id']), 'lines' => collect($shortfalls)->map(fn ($s) => __('warehouse.outbound.shortage_exception_line', ['line' => $s['order_line_id'], 'need' => $s['requested_qty'], 'reserved' => $s['reserved_qty'], 'short' => $s['shortfall_qty']]))->join(';')]),
            ]);
        }
    }
}
