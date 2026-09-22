<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Events\DeliveryExtraCharge;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\ShipmentExtraCharge;
use App\Support\Outbox\OutboxPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ExtraChargeService
{
    public const CHARGE_TYPES = ['waiting', 'redelivery', 'failed', 'other'];

    public const UOMS = ['delivery', 'man_hour'];

    /**
     * CHANGE_REQUESTS #135 (audit TMS-11): an extra charge is something that happened on the road, so it is reported from
     * booking onward — a 待报价 shipment with no carrier has nothing to report.
     */
    public const REPORTABLE_STATUSES = ['booked', 'dispatched', 'in_transit', 'delivered', 'failed'];

    public function __construct(private readonly OutboxPublisher $outbox) {}

    /** True while the shipment may take a report (the form is offered and the controller accepts). */
    public static function reportable(Shipment $shipment): bool
    {
        return in_array($shipment->status, self::REPORTABLE_STATUSES, true);
    }

    /**
     * Records the report (shipment_extra_charges) and publishes `delivery.extra_charge` in the same transaction. The same
     * charge type reported before on this shipment is refused unless the reporter explicitly confirmed the repeat
     * (确认再次上报) — Billing keys every report separately, so a silent repeat would bill the client twice.
     */
    public function report(
        Shipment $shipment,
        string $chargeType,
        float $qty,
        string $uom,
        ?int $costCents,
        string $note,
        User $reportedBy,
        bool $confirmRepeat = false,
    ): ShipmentExtraCharge {
        if (! in_array($chargeType, self::CHARGE_TYPES, true)
            || ! in_array($uom, self::UOMS, true)
            || $qty <= 0
            || ($costCents !== null && $costCents < 0)) {
            throw new DomainException(__('transport.extra_charges.invalid'));
        }

        return DB::transaction(function () use ($shipment, $chargeType, $qty, $uom, $costCents, $note, $reportedBy, $confirmRepeat): ShipmentExtraCharge {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if (! self::reportable($locked)) {
                throw new DomainException(__('transport.extra_charges.not_booked'));
            }

            $earlier = ShipmentExtraCharge::query()
                ->where('shipment_id', $locked->id)
                ->where('charge_type', $chargeType)
                ->lockForUpdate()
                ->orderByDesc('reported_at')
                ->first();
            if ($earlier !== null && ! $confirmRepeat) {
                throw new DomainException(__('transport.extra_charges.already_reported', [
                    'type' => __('transport.extra_charges.types.'.$chargeType),
                    'time' => $earlier->reported_at->format('Y-m-d H:i'),
                ]));
            }

            $occurredAt = now();
            $event = new DeliveryExtraCharge([
                'shipment_id' => $locked->id,
                'shipment_no' => $locked->shipment_no,
                'job_id' => $locked->job_id,
                'client_id' => $locked->client_id,
                'order_id' => $locked->order_id,
                'charge_type' => $chargeType,
                'qty' => $qty,
                'uom' => $uom,
                'cost_cents' => $costCents,
                'note' => trim($note),
                'reported_by' => $reportedBy->id,
                'occurred_at' => $occurredAt->toIso8601String(),
            ], jobId: $locked->job_id, clientId: $locked->client_id, correlationId: $locked->shipment_no);

            $record = ShipmentExtraCharge::query()->create([
                'shipment_id' => $locked->id,
                'charge_type' => $chargeType,
                'qty' => $qty,
                'uom' => $uom,
                'cost_cents' => $costCents,
                'note' => trim($note),
                'reported_by' => $reportedBy->id,
                'reported_at' => $occurredAt,
                'event_id' => $event->eventId,
            ]);
            $this->outbox->publish($event);

            return $record->refresh();
        });
    }
}
