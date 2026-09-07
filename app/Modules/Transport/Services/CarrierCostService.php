<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\CarrierCost;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CarrierCostService
{
    public function recordExpected(Shipment $shipment, TransportQuote $quote): CarrierCost
    {
        return DB::transaction(function () use ($shipment, $quote): CarrierCost {
            $lockedShipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $lockedQuote = TransportQuote::query()->lockForUpdate()->findOrFail($quote->id);
            $this->assertSelectedQuote($lockedShipment, $lockedQuote);

            if ($lockedQuote->source === 'own_fleet') {
                throw new DomainException(__('transport.costs.own_fleet_manual'));
            }

            return CarrierCost::query()->firstOrCreate(
                ['shipment_id' => $lockedShipment->id],
                [
                    'job_id' => $lockedShipment->job_id,
                    'carrier_id' => $lockedQuote->carrier_id,
                    'expected_cost_cents' => $lockedQuote->cost_cents,
                ],
            );
        });
    }

    public function recordOwnFleet(Shipment $shipment, int $costCents, string $note): CarrierCost
    {
        return DB::transaction(function () use ($shipment, $costCents, $note): CarrierCost {
            $lockedShipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $quote = $lockedShipment->selected_quote_id === null
                ? null
                : TransportQuote::query()->lockForUpdate()->find($lockedShipment->selected_quote_id);

            if ($quote === null || $quote->source !== 'own_fleet') {
                throw new DomainException(__('transport.costs.own_fleet_only'));
            }
            $this->assertSelectedQuote($lockedShipment, $quote);

            if (! in_array($lockedShipment->status, ['booked', 'dispatched', 'in_transit', 'delivered', 'failed'], true)) {
                throw new DomainException(__('transport.costs.booking_required'));
            }

            return CarrierCost::query()->updateOrCreate(
                ['shipment_id' => $lockedShipment->id],
                [
                    'job_id' => $lockedShipment->job_id,
                    'carrier_id' => $quote->carrier_id,
                    'expected_cost_cents' => $costCents,
                    'actual_cost_cents' => $costCents,
                    'note' => trim($note),
                    'confirmed_at' => now(),
                ],
            );
        });
    }

    /** B9b reuses this when an imported carrier invoice confirms the actual cost. */
    public function confirmActual(CarrierCost $cost, int $actualCostCents, ?string $note = null): CarrierCost
    {
        return DB::transaction(function () use ($cost, $actualCostCents, $note): CarrierCost {
            $locked = CarrierCost::query()->lockForUpdate()->findOrFail($cost->id);
            $locked->fill([
                'actual_cost_cents' => $actualCostCents,
                'note' => $note === null ? $locked->note : trim($note),
                'confirmed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    private function assertSelectedQuote(Shipment $shipment, TransportQuote $quote): void
    {
        if ($shipment->selected_quote_id !== $quote->id
            || $quote->shipment_id !== $shipment->id
            || $quote->quote_stage !== 'final'
            || $quote->status !== 'selected'
            || $shipment->carrier_id !== $quote->carrier_id) {
            throw new DomainException(__('transport.costs.invalid_quote'));
        }
    }
}
