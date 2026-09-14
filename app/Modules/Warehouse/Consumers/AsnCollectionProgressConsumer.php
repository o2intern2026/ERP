<?php

namespace App\Modules\Warehouse\Consumers;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Outbox\EventConsumer;
use Illuminate\Support\Facades\DB;

/**
 * 我方上门提货 (CHANGE_REQUESTS #124): Transport's shipment events carry `asn_id` for an inbound collection. Warehouse copies the
 * progress back onto the 预报单 for display — `shipment.quote_confirmed` → confirmed (+ the plan and client price),
 * `shipment.booked` → booked, `delivery.pod_captured` → the goods are at our dock: the ASN is marked arrived (once) and the
 * collection delivered, `delivery.failed` → failed. Events without `asn_id` (order shipments) are ignored. Idempotent: every
 * write is a plain state copy, and the dispatcher's consumed_events row stops replays anyway.
 */
final class AsnCollectionProgressConsumer implements EventConsumer
{
    public const EVENTS = ['shipment.quote_confirmed', 'shipment.booked', 'delivery.pod_captured', 'delivery.failed'];

    public function __construct(private readonly AsnService $asns) {}

    public function handle(array $envelope): void
    {
        $p = $envelope['payload'];
        $asnId = (int) ($p['asn_id'] ?? 0);
        if ($asnId === 0) {
            return;
        }

        DB::transaction(function () use ($envelope, $p, $asnId): void {
            $asn = Asn::query()->withoutGlobalScopes()->lockForUpdate()->find($asnId);
            if ($asn === null || ! $asn->isCollection()) {
                return; // cancelled meanwhile (改为客户自送) or unknown — nothing to show
            }
            $shipmentId = isset($p['shipment_id']) ? (int) $p['shipment_id'] : null;

            match ($envelope['event_name']) {
                'shipment.quote_confirmed' => $this->asns->recordCollectionProgress($asn, 'confirmed', $shipmentId, $this->plan($p)),
                'shipment.booked' => $this->asns->recordCollectionProgress($asn, 'booked', $shipmentId, $this->booking($asn, $p)),
                'delivery.pod_captured' => $this->delivered($asn, $shipmentId),
                'delivery.failed' => $this->asns->recordCollectionProgress($asn, 'failed', $shipmentId),
                default => null,
            };
        });
    }

    /** The confirmed plan as the ASN page and the portal show it: carrier, service level, client price — never the carrier cost. */
    private function plan(array $p): array
    {
        $carrierId = isset($p['carrier_id']) ? (int) $p['carrier_id'] : null;

        return [
            'shipment_no' => $p['shipment_no'] ?? null,
            'source' => $p['source'] ?? null,
            'carrier_id' => $carrierId,
            'carrier_name' => $carrierId === null ? null : Carrier::query()->whereKey($carrierId)->value('name'),
            'service_level' => $p['service_level'] ?? null,
            'customer_price_cents' => (int) ($p['customer_price_cents'] ?? 0),
            'eta_days' => $p['eta_days'] ?? null,
            'confirmed_by_type' => $p['confirmed_by_type'] ?? null,
            'confirmed_at' => $p['confirmed_at'] ?? null,
        ];
    }

    /** The booking reference / tracking number on top of the confirmed plan. */
    private function booking(Asn $asn, array $p): array
    {
        return ($asn->collection_plan ?? []) + ['shipment_no' => $p['shipment_no'] ?? null] + array_filter([
            'booking_ref' => $p['booking_ref'] ?? null,
            'tracking_number' => $p['tracking_number'] ?? null,
            'booked_at' => $p['booked_at'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /** POD at our dock = the goods arrived: same as 登记到货, once; receiving / putaway proceed as usual. */
    private function delivered(Asn $asn, ?int $shipmentId): void
    {
        if ($asn->status === 'booked') {
            $this->asns->markArrived($asn);
        }
        $this->asns->recordCollectionProgress($asn, 'delivered', $shipmentId);
    }
}
