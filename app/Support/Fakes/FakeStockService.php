<?php

namespace App\Support\Fakes;

use App\Support\Contracts\StockService;

/**
 * In-memory stock. An unseeded asn_line has 100 cartons on hand; call seed() in tests for other quantities.
 */
final class FakeStockService implements StockService
{
    public const DEFAULT_ON_HAND = 100;

    /** @var array<int, array<int, array{on_hand:int, reserved:int}>> [clientId][asnLineId] */
    private array $stock = [];

    /** @var array<int, array{order_id:int, order_line_id:int, asn_line_id:int, client_id:int, qty:int, status:string}> */
    private array $reservations = [];

    private int $nextReservationId = 1;

    public function seed(int $clientId, int $asnLineId, int $qtyOnHand): void
    {
        $this->stock[$clientId][$asnLineId] = ['on_hand' => $qtyOnHand, 'reserved' => 0];
    }

    public function onHand(int $clientId, int $asnLineId): array
    {
        $row = $this->row($clientId, $asnLineId);

        return [
            'qty_on_hand' => $row['on_hand'],
            'qty_reserved' => $row['reserved'],
            'qty_available' => $row['on_hand'] - $row['reserved'],
        ];
    }

    public function reserve(int $clientId, int $orderId, array $lines): array
    {
        $result = [];

        foreach ($lines as $line) {
            $row = $this->row($clientId, $line['asn_line_id']);
            $available = $row['on_hand'] - $row['reserved'];
            $take = max(0, min($line['qty'], $available));
            $ids = [];

            if ($take > 0) {
                $id = $this->nextReservationId++;
                $this->reservations[$id] = [
                    'order_id' => $orderId,
                    'order_line_id' => $line['order_line_id'],
                    'asn_line_id' => $line['asn_line_id'],
                    'client_id' => $clientId,
                    'qty' => $take,
                    'status' => 'active',
                ];
                $this->stock[$clientId][$line['asn_line_id']]['reserved'] += $take;
                $ids[] = $id;
            }

            $result[] = [
                'order_line_id' => $line['order_line_id'],
                'asn_line_id' => $line['asn_line_id'],
                'requested_qty' => $line['qty'],
                'reserved_qty' => $take,
                'shortfall_qty' => $line['qty'] - $take,
                'reservation_ids' => $ids,
            ];
        }

        return $result;
    }

    public function release(int $orderId, ?int $orderLineId = null, string $reason = 'order_cancelled'): int
    {
        $released = 0;

        foreach ($this->reservations as $id => $r) {
            if ($r['status'] !== 'active' || $r['order_id'] !== $orderId) {
                continue;
            }
            if ($orderLineId !== null && $r['order_line_id'] !== $orderLineId) {
                continue;
            }
            $this->reservations[$id]['status'] = 'released';
            $this->stock[$r['client_id']][$r['asn_line_id']]['reserved'] -= $r['qty'];
            $released += $r['qty'];
        }

        return $released;
    }

    /** @return array{on_hand:int, reserved:int} */
    private function row(int $clientId, int $asnLineId): array
    {
        return $this->stock[$clientId][$asnLineId] ??= ['on_hand' => self::DEFAULT_ON_HAND, 'reserved' => 0];
    }
}
