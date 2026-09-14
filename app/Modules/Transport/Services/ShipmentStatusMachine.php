<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Support\TransportEnums;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ShipmentStatusMachine
{
    private const DELIVERY_TRANSITIONS = [
        'quoting' => ['quoted', 'booking_cancelled'],
        'quoted' => ['quote_confirmed', 'booking_cancelled'],
        // B5d: a materially changed final quote returns to `quoted` for re-confirmation.
        'quote_confirmed' => ['quoted', 'booked', 'booking_cancelled'],
        'booked' => ['dispatched', 'booking_cancelled'],
        'dispatched' => ['in_transit', 'failed'],
        'in_transit' => ['delivered', 'failed'],
        'delivered' => [],
        'failed' => [],
        'booking_cancelled' => [],
    ];

    private const TRANSITIONS = [
        'outbound' => self::DELIVERY_TRANSITIONS,
        'inbound_collection' => self::DELIVERY_TRANSITIONS, // 我方上门提货 (CHANGE_REQUESTS #124): same lifecycle, receiver = our warehouse
        'return' => [
            'return_requested' => ['return_in_transit'],
            'return_in_transit' => ['arrived_warehouse'],
            'arrived_warehouse' => [],
        ],
    ];

    /** @return list<string> */
    public function allowedTransitions(Shipment $shipment): array
    {
        return self::TRANSITIONS[$shipment->shipment_type][$shipment->status] ?? [];
    }

    public function canTransition(Shipment $shipment, string $target): bool
    {
        return in_array($target, $this->allowedTransitions($shipment), true);
    }

    public function transition(Shipment $shipment, string $target): Shipment
    {
        if (! in_array($target, TransportEnums::shipmentStatuses($shipment->shipment_type), true)) {
            throw new InvalidArgumentException("Unknown target status: {$target}");
        }

        return DB::transaction(function () use ($shipment, $target): Shipment {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->getKey());

            if (! $this->canTransition($locked, $target)) {
                throw new DomainException(__('transport.tracking.invalid_transition', [
                    'from' => $locked->status,
                    'to' => $target,
                ]));
            }

            $locked->status = $target;
            $locked->save();

            return $locked->refresh();
        });
    }
}
