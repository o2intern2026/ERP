<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use Carbon\CarbonInterface;
use DomainException;

/** Advances an outbound shipment through every contract status instead of skipping transitions. */
final class ShipmentProgressService
{
    private const FORWARD = [
        'quoting', 'quoted', 'quote_confirmed', 'booked', 'dispatched', 'in_transit', 'delivered',
    ];

    public function __construct(private readonly ShipmentStatusMachine $statuses) {}

    public function advance(Shipment $shipment, string $target, CarbonInterface $occurredAt): Shipment
    {
        if ($shipment->shipment_type !== 'outbound') {
            throw new DomainException(__('transport.tracking.outbound_only'));
        }

        if ($shipment->status === $target || in_array($shipment->status, ['delivered', 'failed', 'booking_cancelled'], true)) {
            return $shipment;
        }

        $targets = $target === 'failed'
            ? $this->failurePath($shipment->status)
            : $this->forwardPath($shipment->status, $target);

        foreach ($targets as $next) {
            if (! $this->statuses->canTransition($shipment, $next)) {
                throw new DomainException(__('transport.tracking.invalid_transition', [
                    'from' => $shipment->status,
                    'to' => $next,
                ]));
            }

            $shipment->status = $next;
            if ($next === 'dispatched' && $shipment->dispatched_at === null) {
                $shipment->dispatched_at = $occurredAt;
            }
            if ($next === 'delivered') {
                $shipment->delivered_at = $occurredAt;
            }
            $shipment->save();
        }

        return $shipment->refresh();
    }

    /** @return list<string> */
    private function forwardPath(string $current, string $target): array
    {
        $currentIndex = array_search($current, self::FORWARD, true);
        $targetIndex = array_search($target, self::FORWARD, true);
        if ($currentIndex === false || $targetIndex === false || $targetIndex < $currentIndex) {
            throw new DomainException(__('transport.tracking.invalid_transition', ['from' => $current, 'to' => $target]));
        }

        return array_slice(self::FORWARD, $currentIndex + 1, $targetIndex - $currentIndex);
    }

    /** @return list<string> */
    private function failurePath(string $current): array
    {
        $path = in_array($current, ['dispatched', 'in_transit'], true)
            ? []
            : $this->forwardPath($current, 'dispatched');
        $path[] = 'failed';

        return $path;
    }
}
