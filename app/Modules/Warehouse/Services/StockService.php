<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\StockReservation;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\StockService as StockServiceContract;
use Illuminate\Support\Facades\DB;

/**
 * B1 / B4a. Quantities are cartons. Only put-away, good stock is available; a reservation is a quantity on the unit,
 * tracked line by line in stock_reservations (ERP_PLAN §4.3 rules 1, 2, 4, 10). Events are emitted by the consumers
 * that call these methods (they hold the correlation context of the incoming order event).
 */
final class StockService implements StockServiceContract
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function onHand(int $clientId, int $asnLineId): array
    {
        $units = StockUnit::query()->withoutGlobalScopes()
            ->where('client_id', $clientId)->where('asn_line_id', $asnLineId)
            ->where('putaway_completed', true)->where('condition', 'good')
            ->get(['qty_on_hand', 'qty_reserved']);

        $onHand = (int) $units->sum('qty_on_hand');
        $reserved = (int) $units->sum('qty_reserved');

        return ['qty_on_hand' => $onHand, 'qty_reserved' => $reserved, 'qty_available' => max(0, $onHand - $reserved)];
    }

    public function reserve(int $clientId, int $orderId, array $lines): array
    {
        return DB::transaction(function () use ($clientId, $orderId, $lines): array {
            $result = [];

            foreach ($lines as $line) {
                $remaining = (int) $line['qty'];
                $reservationIds = [];

                // FIFO by received_at; row locks stop two orders taking the same cartons (§4.3 rule 10).
                $units = StockUnit::query()->withoutGlobalScopes()
                    ->where('client_id', $clientId)->where('asn_line_id', $line['asn_line_id'])
                    ->where('putaway_completed', true)->where('condition', 'good')
                    ->whereColumn('qty_on_hand', '>', 'qty_reserved')
                    ->orderBy('received_at')->orderBy('id')
                    ->lockForUpdate()->get();

                foreach ($units as $unit) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min($remaining, $unit->availableQty());
                    if ($take <= 0) {
                        continue;
                    }
                    $unit->increment('qty_reserved', $take);
                    $reservationIds[] = StockReservation::query()->create([
                        'order_id' => $orderId,
                        'order_line_id' => $line['order_line_id'],
                        'stock_unit_id' => $unit->id,
                        'qty' => $take,
                        'status' => 'active',
                        'created_at' => now(),
                    ])->id;
                    $remaining -= $take;
                }

                $result[] = [
                    'order_line_id' => (int) $line['order_line_id'],
                    'asn_line_id' => (int) $line['asn_line_id'],
                    'requested_qty' => (int) $line['qty'],
                    'reserved_qty' => (int) $line['qty'] - $remaining,
                    'shortfall_qty' => $remaining,
                    'reservation_ids' => $reservationIds,
                ];
            }

            return $result;
        });
    }

    public function release(int $orderId, ?int $orderLineId = null, string $reason = 'order_cancelled'): int
    {
        return DB::transaction(function () use ($orderId, $orderLineId, $reason): int {
            $reservations = StockReservation::query()
                ->where('order_id', $orderId)->where('status', 'active')
                ->when($orderLineId !== null, fn ($q) => $q->where('order_line_id', $orderLineId))
                ->lockForUpdate()->get();

            $released = 0;
            foreach ($reservations as $reservation) {
                $unit = StockUnit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->stock_unit_id);
                $this->ledger->record($unit, 'release', -$reservation->qty, ['source_type' => 'order', 'source_id' => $orderId]);
                $reservation->update(['status' => 'released', 'released_at' => now(), 'released_reason' => $reason]);
                $released += $reservation->qty;
            }

            return $released;
        });
    }

    /** Reservations consumed by picking (B4) — recorded here so the projection stays exact. */
    public function consume(int $orderId, int $orderLineId, int $qty): void
    {
        StockReservation::query()->where('order_id', $orderId)->where('order_line_id', $orderLineId)
            ->where('status', 'active')->orderBy('id')->limit(1)->update(['status' => 'consumed']);
    }
}
