<?php

namespace App\Support\Contracts;

/**
 * Provided by Warehouse (seat C, M2). contracts/services.md §1.
 * Quantities are cartons; a pallet unit counts the cartons it holds. Only putaway-completed, condition=good stock is available.
 */
interface StockService
{
    /**
     * @return array{qty_on_hand:int, qty_reserved:int, qty_available:int}
     */
    public function onHand(int $clientId, int $asnLineId): array;

    /**
     * Reserve specific stock units for an order (consumer of order.confirmed). Row-locks the units;
     * partial reservation is allowed and reported as shortfall. Emits stock.reserved / stock.reservation_failed.
     *
     * @param  list<array{order_line_id:int, asn_line_id:int, qty:int}>  $lines
     * @return list<array{order_line_id:int, asn_line_id:int, requested_qty:int, reserved_qty:int, shortfall_qty:int, reservation_ids:list<int>}>
     */
    public function reserve(int $clientId, int $orderId, array $lines): array;

    /**
     * Release active reservations for an order (or one order line). Writes stock_ledger, emits stock.released.
     * Returns the number of cartons released.
     */
    public function release(int $orderId, ?int $orderLineId = null, string $reason = 'order_cancelled'): int;
}
