<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Models\Fulfilment;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Models\OrderLine;
use App\Support\Contracts\StockService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A7 + block 2: turns Warehouse / Transport events into Orders-owned fulfilment batches and derived progress.
 * Warehouse remains the only stock writer; Orders stores only the commercial fulfilment view. The order's
 * operational status follows the slowest batch (picking when any batch is picked, packed / dispatched only when
 * every batch is, delivered only when every ordered carton is delivered — §3.8 #8) and never moves backwards.
 */
final class FulfilmentService
{
    /** fulfilments.status in progress order (contracts/enums.md §3). */
    public const BATCH_RANK = ['allocated' => 0, 'picking' => 1, 'packed' => 2, 'dispatched' => 3, 'delivered' => 4];

    /** The operational path a from_stock order walks after confirmation. */
    private const OPERATIONAL_PATH = ['confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered'];

    public function __construct(private readonly StockService $stock, private readonly OrderStatusService $statuses) {}

    /**
     * @return list<array{line:OrderLine,allocated_qty:int,remaining_qty:int,qty_on_hand:?int,qty_reserved:?int,qty_available:?int,allocatable_qty:int,status:string}>
     */
    public function availability(Order $order): array
    {
        $order->loadMissing('lines.fulfilmentLines');

        return $order->lines->map(function (OrderLine $line) use ($order): array {
            $allocated = (int) $line->fulfilmentLines->sum('qty');
            $remaining = max(0, $line->carton_qty - $allocated);

            if ($line->asn_line_id === null) {
                return [
                    'line' => $line,
                    'allocated_qty' => $allocated,
                    'remaining_qty' => $remaining,
                    'qty_on_hand' => null,
                    'qty_reserved' => null,
                    'qty_available' => null,
                    'allocatable_qty' => 0,
                    'status' => $remaining === 0 ? 'allocated' : 'unlinked',
                ];
            }

            $stock = $this->stock->onHand($order->client_id, $line->asn_line_id);
            $allocatable = min($remaining, $stock['qty_available']);

            return [
                'line' => $line,
                'allocated_qty' => $allocated,
                'remaining_qty' => $remaining,
                'qty_on_hand' => $stock['qty_on_hand'],
                'qty_reserved' => $stock['qty_reserved'],
                'qty_available' => $stock['qty_available'],
                'allocatable_qty' => $allocatable,
                'status' => match (true) {
                    $remaining === 0 => 'allocated',
                    $stock['qty_available'] >= $remaining => 'available',
                    $stock['qty_available'] > 0 => 'partial',
                    default => 'unavailable',
                },
            ];
        })->values()->all();
    }

    /**
     * Handles either contracts/events.md `stock.reserved` or `stock.reservation_failed` payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyReservationResult(array $payload): Order
    {
        return DB::transaction(function () use ($payload): Order {
            $order = Order::query()->withoutGlobalScopes()->lockForUpdate()->with('lines')->findOrFail((int) $payload['order_id']);
            $this->assertPayloadMatches($order, $payload);

            // A late reservation reply must not resurrect work already cancelled or completed.
            if (in_array($order->operational_status, OrderStatusService::TERMINAL, true)) {
                return $order;
            }

            $this->createBatches($order, $payload['reservations'] ?? []);
            $this->refreshDerivedProgress($order, __('orders.fulfilments.timeline.allocated'));

            return $order->refresh()->load('lines', 'fulfilments.lines');
        });
    }

    /**
     * A put-away event is the retry signal for outstanding quantities on the same goods lines.
     *
     * @param  array<string, mixed>  $payload
     */
    public function allocateBackordersFromPutaway(array $payload): void
    {
        $clientId = (int) ($payload['client_id'] ?? 0);
        $warehouseId = (int) ($payload['warehouse_id'] ?? 0);
        $asnLineIds = collect($payload['lines'] ?? [])
            ->pluck('asn_line_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($clientId <= 0 || $warehouseId <= 0 || $asnLineIds === []) {
            return;
        }

        $orders = Order::query()->withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->whereNotIn('operational_status', OrderStatusService::TERMINAL)
            ->whereHas('lines', fn ($query) => $query->whereIn('asn_line_id', $asnLineIds)->where('qty_backordered', '>', 0))
            ->with(['lines' => fn ($query) => $query->whereIn('asn_line_id', $asnLineIds)->where('qty_backordered', '>', 0)])
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $requested = $order->lines->map(fn (OrderLine $line) => [
                'order_line_id' => $line->id,
                'asn_line_id' => $line->asn_line_id,
                'qty' => $line->qty_backordered,
            ])->values()->all();

            $result = $this->stock->reserve($order->client_id, $order->id, $requested);
            $reservations = collect($result)->filter(fn ($line) => (int) $line['reserved_qty'] > 0)
                ->map(fn ($line) => [
                    'order_line_id' => (int) $line['order_line_id'],
                    'asn_line_id' => (int) $line['asn_line_id'],
                    'warehouse_id' => $warehouseId,
                    'qty' => (int) $line['reserved_qty'],
                ])->values()->all();

            if ($reservations !== []) {
                $this->applyReservationResult([
                    'order_id' => $order->id,
                    'job_id' => $order->job_id,
                    'client_id' => $order->client_id,
                    'reservations' => $reservations,
                    'shortfalls' => collect($result)->filter(fn ($line) => (int) $line['shortfall_qty'] > 0)->values()->all(),
                ]);
            }
        }
    }

    /**
     * `task.completed` with task_type = pick: the batch is being picked; the order enters `picking` from `allocated`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function markPicking(array $payload): ?Order
    {
        if (($payload['task_type'] ?? null) !== 'pick' || empty($payload['order_id'])) {
            return null;
        }

        return $this->advanceBatch($payload, 'picking', 'orders.fulfilments.timeline.picking');
    }

    /**
     * `outbound.packed`: the batch is packed; the order is `packed` only when every batch is (partial orders stay).
     *
     * @param  array<string, mixed>  $payload
     */
    public function markPacked(array $payload): Order
    {
        return $this->advanceBatch($payload, 'packed', 'orders.fulfilments.timeline.packed');
    }

    /**
     * `outbound.dispatched`: the batch left the warehouse (shipment_id stored when the handover was against a booking).
     *
     * @param  array<string, mixed>  $payload
     */
    public function markDispatched(array $payload): Order
    {
        return $this->advanceBatch($payload, 'dispatched', 'orders.fulfilments.timeline.dispatched', array_filter(['shipment_id' => $payload['shipment_id'] ?? null]));
    }

    /**
     * `delivery.pod_captured`: each POD closes one batch; the order closes only after every ordered carton is delivered.
     *
     * @param  array<string, mixed>  $payload
     */
    public function markDelivered(array $payload): Order
    {
        return $this->advanceBatch($payload, 'delivered', 'orders.fulfilments.timeline.delivered', array_filter(['shipment_id' => $payload['shipment_id'] ?? null]));
    }

    /** Re-derive the operational status from the batches, e.g. after a quantity reduction shrank the fulfilment lines (A11). */
    public function resyncOperationalStatus(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->id);
            if (in_array($locked->operational_status, OrderStatusService::TERMINAL, true)) {
                return $locked;
            }
            $this->refreshDerivedProgress($locked, __('orders.fulfilments.timeline.resynced'));

            return $locked->refresh();
        });
    }

    /**
     * Shared handler for the warehouse / transport progress events: monotonic on the batch, derived on the order,
     * replay-tolerant (same state → no-op) and never touching terminal orders.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra  additional fulfilment columns (shipment_id)
     */
    private function advanceBatch(array $payload, string $status, string $noteKey, array $extra = []): Order
    {
        return DB::transaction(function () use ($payload, $status, $noteKey, $extra): Order {
            $order = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail((int) $payload['order_id']);
            $this->assertPayloadMatches($order, $payload);

            if (in_array($order->operational_status, OrderStatusService::TERMINAL, true)) {
                return $order;
            }

            $fulfilment = null;
            if (! empty($payload['fulfilment_id'])) {
                $fulfilment = Fulfilment::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail((int) $payload['fulfilment_id']);
                if (self::BATCH_RANK[$fulfilment->status] < self::BATCH_RANK[$status]) {
                    $fulfilment->update(['status' => $status] + $extra);
                } elseif (isset($extra['shipment_id']) && $fulfilment->shipment_id === null) {
                    $fulfilment->update(['shipment_id' => $extra['shipment_id']]);
                }
            } elseif ($order->order_type === 'pickup_deliver' && in_array($status, ['dispatched', 'delivered'], true)) {
                // A11b: a pure transport order has no warehouse batch — the POD (or handover) moves the order itself.
                $this->stepOperational($order, $status, __($noteKey, ['seq' => '—']));

                return $order->refresh();
            }

            $this->refreshDerivedProgress($order, __($noteKey, ['seq' => $fulfilment?->seq ?? '—']));

            return $order->refresh()->load('lines', 'fulfilments.lines');
        });
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadMatches(Order $order, array $payload): void
    {
        if ((int) ($payload['client_id'] ?? 0) !== $order->client_id || (int) ($payload['job_id'] ?? 0) !== $order->job_id) {
            throw new InvalidArgumentException('Stock, warehouse or delivery event does not match the order client and Job.');
        }
    }

    /** @param list<array<string, mixed>> $reservations */
    private function createBatches(Order $order, array $reservations): void
    {
        $lineMap = $order->lines->keyBy('id');
        $grouped = collect($reservations)
            ->filter(fn ($reservation) => (int) ($reservation['qty'] ?? 0) > 0)
            ->groupBy(fn ($reservation) => (int) ($reservation['warehouse_id'] ?? 0));

        $nextSequence = $order->fulfilments()->lockForUpdate()->get()
            ->map(fn (Fulfilment $fulfilment) => (int) ltrim($fulfilment->seq, 'F'))
            ->max() + 1;

        foreach ($grouped as $warehouseId => $warehouseReservations) {
            if ((int) $warehouseId <= 0) {
                throw new InvalidArgumentException('A stock reservation must include warehouse_id.');
            }

            $quantities = $warehouseReservations->groupBy('order_line_id')
                ->map(fn ($rows) => (int) $rows->sum(fn ($row) => (int) $row['qty']));

            foreach ($quantities as $lineId => $qty) {
                $line = $lineMap->get((int) $lineId);
                if (! $line || $line->asn_line_id === null) {
                    throw new InvalidArgumentException('A stock reservation references an unknown or unlinked order line.');
                }

                $asnLineIds = $warehouseReservations->where('order_line_id', $lineId)->pluck('asn_line_id')->unique();
                if ($asnLineIds->count() !== 1 || (int) $asnLineIds->first() !== $line->asn_line_id) {
                    throw new InvalidArgumentException('A stock reservation references the wrong ASN goods line.');
                }

                $alreadyAllocated = (int) $line->fulfilmentLines()->sum('qty');
                if ($alreadyAllocated + $qty > $line->carton_qty) {
                    throw new InvalidArgumentException('A stock reservation exceeds the ordered quantity.');
                }
            }

            $fulfilment = $order->fulfilments()->create([
                'seq' => 'F'.$nextSequence++,
                'warehouse_id' => (int) $warehouseId,
                'status' => 'allocated',
            ]);

            foreach ($quantities as $lineId => $qty) {
                $fulfilment->lines()->create(['order_line_id' => (int) $lineId, 'qty' => $qty]);
            }
        }
    }

    private function refreshDerivedProgress(Order $order, string $note): void
    {
        $order->load(['lines', 'fulfilments.lines']);
        $allocatedByLine = $order->fulfilments->flatMap->lines->groupBy('order_line_id')
            ->map(fn ($lines) => (int) $lines->sum('qty'));
        $shippedByLine = $order->fulfilments->filter(fn (Fulfilment $f) => self::BATCH_RANK[$f->status] >= self::BATCH_RANK['dispatched'])
            ->flatMap->lines->groupBy('order_line_id')->map(fn ($lines) => (int) $lines->sum('qty'));
        $deliveredByLine = $order->fulfilments->where('status', 'delivered')->flatMap->lines->groupBy('order_line_id')
            ->map(fn ($lines) => (int) $lines->sum('qty'));

        foreach ($order->lines as $line) {
            $allocated = min($line->carton_qty, (int) ($allocatedByLine[$line->id] ?? 0));
            $shipped = min($line->carton_qty, (int) ($shippedByLine[$line->id] ?? 0));
            $line->update([
                'qty_shipped' => $shipped,
                'qty_backordered' => max(0, $line->carton_qty - $allocated),
            ]);
        }

        $orderedTotal = (int) $order->lines->sum('carton_qty');
        $allocatedTotal = min($orderedTotal, (int) $allocatedByLine->sum());
        $allDelivered = $orderedTotal > 0 && $order->lines->every(
            fn (OrderLine $line) => (int) ($deliveredByLine[$line->id] ?? 0) >= $line->carton_qty,
        );
        $fulfilmentStatus = match (true) {
            $allDelivered => 'fulfilled',
            $allocatedTotal > 0 => 'partial',
            default => 'unfulfilled',
        };

        $this->updateFulfilmentDimension($order, $fulfilmentStatus, $note);
        $this->stepOperational($order, $this->derivedOperationalTarget($order, $fulfilmentStatus), $note);
    }

    /** The operational status the batches imply, or null when the order already is there (or further). */
    private function derivedOperationalTarget(Order $order, string $fulfilmentStatus): ?string
    {
        $current = array_search($order->operational_status, self::OPERATIONAL_PATH, true);
        if ($current === false || $order->fulfilments->isEmpty()) {
            return null;
        }

        $ranks = $order->fulfilments->map(fn (Fulfilment $f) => self::BATCH_RANK[$f->status]);
        $target = match (true) {
            $fulfilmentStatus === 'fulfilled' => 'delivered',
            $ranks->min() >= self::BATCH_RANK['dispatched'] => 'dispatched',
            $ranks->min() >= self::BATCH_RANK['packed'] => 'packed',
            $ranks->max() >= self::BATCH_RANK['picking'] => 'picking',
            default => 'allocated',
        };

        return array_search($target, self::OPERATIONAL_PATH, true) > $current ? $target : null;
    }

    /**
     * Walk the order forward step by step so the timeline shows every implied stage (events may arrive out of order).
     * A financial hold refuses `dispatched` (OrderRuleViolation) — since CHANGE_REQUESTS #40 / #46 Warehouse refuses the
     * handover itself, so such an event is a fault: it propagates, the delivery is retried and dead-lettered (never swallowed).
     */
    private function stepOperational(Order $order, ?string $target, string $note): void
    {
        if ($target === null) {
            return;
        }
        $from = array_search($order->operational_status, self::OPERATIONAL_PATH, true);
        $to = array_search($target, self::OPERATIONAL_PATH, true);
        if ($from === false || $to === false || $to <= $from) {
            return;
        }

        $steps = array_slice(self::OPERATIONAL_PATH, $from + 1, $to - $from);
        if ($order->order_type === 'pickup_deliver') {
            $steps = array_values(array_intersect($steps, ['dispatched', 'delivered']));
        }

        foreach ($steps as $status) {
            $this->statuses->transitionOperational($order, $status, null, $note);
            $order->refresh();
        }
    }

    private function updateFulfilmentDimension(Order $order, string $toStatus, string $note): void
    {
        $fromStatus = $order->fulfilment_status;
        if ($fromStatus === $toStatus) {
            return;
        }

        $order->update(['fulfilment_status' => $toStatus]);
        OrderEvent::query()->create([
            'order_id' => $order->id,
            'dimension' => 'fulfilment',
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => 'system',
            'actor_id' => null,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
