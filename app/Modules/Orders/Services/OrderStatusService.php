<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Events\OrderConfirmed;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\OrderEnums;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Owns the independent operational and billing state transitions and their append-only timeline. */
final class OrderStatusService
{
    private const OPERATIONAL_TRANSITIONS = [
        'received' => ['confirmed', 'cancelled'],
        'confirmed' => ['allocated', 'cancelled'],
        'allocated' => ['picking', 'cancelled'],
        'picking' => ['packed'],
        'packed' => ['dispatched'],
        'dispatched' => ['delivered'],
        'delivered' => ['returned'],
        'returned' => [],
        'cancelled' => [],
    ];

    private const BILLING_TRANSITIONS = [
        'unbilled' => ['partially_billed', 'billed'],
        'partially_billed' => ['billed'],
        'billed' => ['credited'],
        'credited' => [],
    ];

    public function transitionOperational(Order|int $order, string $toStatus, ?int $actorId = null, ?string $note = null): Order
    {
        if (! in_array($toStatus, OrderEnums::OPERATIONAL_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown operational_status: {$toStatus}");
        }

        return DB::transaction(function () use ($order, $toStatus, $actorId, $note): Order {
            $locked = $this->lock($order);
            $fromStatus = $locked->operational_status;
            $this->guardTransition(self::OPERATIONAL_TRANSITIONS, $fromStatus, $toStatus, 'operational');

            $locked->update(['operational_status' => $toStatus]);
            $this->record($locked, 'operational', $fromStatus, $toStatus, $actorId, $note);

            if ($toStatus === 'confirmed') {
                $locked->loadMissing('lines', 'declaredPackages');
                app(TailgateRule::class)->apply($locked); // A16: automatic unless a person overrode it
                app(OutboxPublisher::class)->publish($this->confirmedEvent($locked, $actorId));
            }

            return $locked->refresh();
        });
    }

    public function transitionBilling(Order|int $order, string $toStatus, ?int $actorId = null, ?string $note = null): Order
    {
        if (! in_array($toStatus, OrderEnums::BILLING_STATUSES, true)) {
            throw new InvalidArgumentException("Unknown billing_status: {$toStatus}");
        }

        return DB::transaction(function () use ($order, $toStatus, $actorId, $note): Order {
            $locked = $this->lock($order);
            $fromStatus = $locked->billing_status;
            $this->guardTransition(self::BILLING_TRANSITIONS, $fromStatus, $toStatus, 'billing');
            $locked->update(['billing_status' => $toStatus]);
            $this->record($locked, 'billing', $fromStatus, $toStatus, $actorId, $note);

            return $locked->refresh();
        });
    }

    private function lock(Order|int $order): Order
    {
        return Order::query()->lockForUpdate()->findOrFail($order instanceof Order ? $order->id : $order);
    }

    /** @param array<string, list<string>> $map */
    private function guardTransition(array $map, string $from, string $to, string $dimension): void
    {
        if (! in_array($to, $map[$from] ?? [], true)) {
            throw new InvalidArgumentException("Invalid {$dimension} status transition: {$from} -> {$to}");
        }
    }

    private function record(Order $order, string $dimension, string $from, string $to, ?int $actorId, ?string $note): void
    {
        OrderEvent::query()->create([
            'order_id' => $order->id,
            'dimension' => $dimension,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorId === null ? 'system' : 'user',
            'actor_id' => $actorId,
            'note' => $note,
            'created_at' => now(),
        ]);
    }

    private function confirmedEvent(Order $order, ?int $actorId): OrderConfirmed
    {
        return new OrderConfirmed(
            payload: [
                'order_id' => $order->id,
                'order_no' => $order->order_no,
                'order_type' => $order->order_type,
                'client_id' => $order->client_id,
                'job_id' => $order->job_id,
                'source' => $order->source,
                'service_level' => $order->service_level,
                'requested_date' => $order->requested_date->toDateString(),
                'tailgate_required' => $order->tailgate_required,
                'tailgate_reason' => $order->tailgate_reason,
                'deliver_to' => [
                    'name' => $order->deliver_to_name,
                    'phone' => $order->deliver_to_phone,
                    'address' => $order->deliver_to_address,
                    'suburb' => $order->deliver_to_suburb,
                    'state' => $order->deliver_to_state,
                    'postcode' => $order->deliver_to_postcode,
                    'address_type' => $order->deliver_to_address_type,
                ],
                'pickup_address' => $order->pickup_address,
                'lines' => $order->lines->map(fn ($line) => [
                    'order_line_id' => $line->id,
                    'asn_line_id' => $line->asn_line_id,
                    'carton_qty' => $line->carton_qty,
                    'unit_qty' => $line->unit_qty,
                    'actual_weight_kg' => $line->actual_weight_kg === null ? null : (float) $line->actual_weight_kg,
                    'length_mm' => $line->length_mm,
                    'width_mm' => $line->width_mm,
                    'height_mm' => $line->height_mm,
                    'cbm' => $line->cbm === null ? null : (float) $line->cbm,
                ])->values()->all(),
                'declared_packages' => $order->declaredPackages->map(fn ($package) => [
                    'package_type' => $package->package_type,
                    'qty' => $package->qty,
                    'weight_kg' => $package->weight_kg === null ? null : (float) $package->weight_kg,
                    'length_mm' => $package->length_mm,
                    'width_mm' => $package->width_mm,
                    'height_mm' => $package->height_mm,
                ])->values()->all(),
                'confirmed_by' => $actorId,
                'confirmed_at' => now()->toIso8601String(),
            ],
            jobId: $order->job_id,
            clientId: $order->client_id,
            correlationId: $order->job?->job_no ?? $order->order_no,
        );
    }
}
