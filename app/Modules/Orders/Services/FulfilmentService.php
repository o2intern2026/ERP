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
 * A7: turns Warehouse reservation results into Orders-owned fulfilment batches and derived progress.
 * Warehouse remains the only stock writer; Orders stores only the commercial fulfilment view.
 */
final class FulfilmentService
{
    public function __construct(private readonly StockService $stock) {}

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
            if (in_array($order->operational_status, ['cancelled', 'returned', 'delivered'], true)) {
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
            ->whereNotIn('operational_status', ['cancelled', 'returned', 'delivered'])
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

    /** @param array<string, mixed> $payload */
    public function markDelivered(array $payload): Order
    {
        return DB::transaction(function () use ($payload): Order {
            $order = Order::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail((int) $payload['order_id']);
            $this->assertPayloadMatches($order, $payload);

            if ($order->operational_status === 'cancelled') {
                return $order;
            }

            $fulfilment = Fulfilment::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->findOrFail((int) $payload['fulfilment_id']);
            $fulfilment->update([
                'status' => 'delivered',
                'shipment_id' => (int) $payload['shipment_id'],
            ]);

            $this->refreshDerivedProgress($order, __('orders.fulfilments.timeline.delivered'));

            return $order->refresh()->load('lines', 'fulfilments.lines');
        });
    }

    /** @param array<string, mixed> $payload */
    private function assertPayloadMatches(Order $order, array $payload): void
    {
        if ((int) ($payload['client_id'] ?? 0) !== $order->client_id || (int) ($payload['job_id'] ?? 0) !== $order->job_id) {
            throw new InvalidArgumentException('Stock or delivery event does not match the order client and Job.');
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
        $deliveredByLine = $order->fulfilments->where('status', 'delivered')->flatMap->lines->groupBy('order_line_id')
            ->map(fn ($lines) => (int) $lines->sum('qty'));

        foreach ($order->lines as $line) {
            $allocated = min($line->carton_qty, (int) ($allocatedByLine[$line->id] ?? 0));
            $delivered = min($line->carton_qty, (int) ($deliveredByLine[$line->id] ?? 0));
            $line->update([
                'qty_shipped' => $delivered,
                'qty_backordered' => max(0, $line->carton_qty - $allocated),
            ]);
        }

        $orderedTotal = (int) $order->lines->sum('carton_qty');
        $allocatedTotal = min($orderedTotal, (int) $allocatedByLine->sum());
        $deliveredTotal = min($orderedTotal, (int) $deliveredByLine->sum());
        $allDelivered = $orderedTotal > 0 && $order->lines->every(
            fn (OrderLine $line) => (int) ($deliveredByLine[$line->id] ?? 0) >= $line->carton_qty,
        );
        $fulfilmentStatus = match (true) {
            $allDelivered => 'fulfilled',
            $allocatedTotal > 0 => 'partial',
            default => 'unfulfilled',
        };

        $this->updateDimension($order, 'fulfilment', 'fulfilment_status', $fulfilmentStatus, $note);

        $operationalStatus = match (true) {
            $fulfilmentStatus === 'fulfilled' => 'delivered',
            $deliveredTotal > 0 && ! in_array($order->operational_status, ['dispatched', 'delivered'], true) => 'dispatched',
            $allocatedTotal > 0 && $order->operational_status === 'confirmed' => 'allocated',
            default => $order->operational_status,
        };
        $this->updateDimension($order, 'operational', 'operational_status', $operationalStatus, $note);
    }

    private function updateDimension(Order $order, string $dimension, string $column, string $toStatus, string $note): void
    {
        $fromStatus = $order->{$column};
        if ($fromStatus === $toStatus) {
            return;
        }

        $order->update([$column => $toStatus]);
        OrderEvent::query()->create([
            'order_id' => $order->id,
            'dimension' => $dimension,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => 'system',
            'actor_id' => null,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
