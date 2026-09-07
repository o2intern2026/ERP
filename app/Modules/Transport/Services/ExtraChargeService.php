<?php

namespace App\Modules\Transport\Services;

use App\Models\User;
use App\Modules\Transport\Events\DeliveryExtraCharge;
use App\Modules\Transport\Models\Shipment;
use App\Support\Outbox\OutboxPublisher;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ExtraChargeService
{
    public const CHARGE_TYPES = ['waiting', 'redelivery', 'failed', 'other'];

    public const UOMS = ['delivery', 'man_hour'];

    public function __construct(private readonly OutboxPublisher $outbox) {}

    public function report(
        Shipment $shipment,
        string $chargeType,
        float $qty,
        string $uom,
        ?int $costCents,
        string $note,
        User $reportedBy,
    ): void {
        if (! in_array($chargeType, self::CHARGE_TYPES, true)
            || ! in_array($uom, self::UOMS, true)
            || $qty <= 0) {
            throw new DomainException(__('transport.extra_charges.invalid'));
        }

        DB::transaction(function () use ($shipment, $chargeType, $qty, $uom, $costCents, $note, $reportedBy): void {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $occurredAt = now();

            $this->outbox->publish(new DeliveryExtraCharge([
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
            ], jobId: $locked->job_id, clientId: $locked->client_id, correlationId: $locked->shipment_no));
        });
    }
}
