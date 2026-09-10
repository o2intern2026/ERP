<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Events\AsnPutawayCompleted;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Exceptions\RuleViolation;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Facades\DB;

/**
 * B2 putaway: receiving area → chosen location (exists, same warehouse, active, storage/pickface — §4.3 rule 3),
 * unit becomes available; when every unit of the ASN is put away the ASN moves to `putaway` and emits
 * asn.putaway_completed with the billing payload (pallets, pallet_source, label count).
 */
final class PutawayService
{
    public function __construct(private readonly StockLedger $ledger, private readonly OutboxPublisher $outbox) {}

    public function putaway(StockUnit $unit, Location $location): StockUnit
    {
        return DB::transaction(function () use ($unit, $location): StockUnit {
            $asn = $unit->asnLine->asn;
            if ($asn->unplanned && ! $asn->unplanned_confirmed) {
                throw new RuleViolation('Unplanned arrivals must be confirmed by a coordinator before putaway.', 'warehouse.putaway.errors.unplanned_unconfirmed');
            }
            if (! $location->active || $location->warehouse_id !== $unit->warehouse_id || ! in_array($location->type, ['storage', 'pickface', 'quarantine'], true)) {
                throw new RuleViolation("Location {$location->full_code} is not a valid putaway target for {$unit->label_code}.", 'warehouse.putaway.errors.invalid_target', ['code' => $location->full_code, 'label' => $unit->label_code]);
            }
            if ($unit->condition !== 'good' && $location->type !== 'quarantine') {
                throw new RuleViolation('Damaged / quarantined stock must be put away into a quarantine location.', 'warehouse.putaway.errors.held_needs_quarantine');
            }

            $this->ledger->record($unit, 'putaway', 0, ['from_location_id' => $unit->location_id, 'to_location_id' => $location->id, 'source_type' => 'asn', 'source_id' => $asn->id]);
            $unit->update(['putaway_completed' => true, 'pallet_class' => $location->type === 'pickface' ? 'pickface' : $unit->pallet_class]);

            $this->completeIfDone($asn->fresh());

            return $unit->fresh();
        });
    }

    private function completeIfDone(Asn $asn): void
    {
        $units = StockUnit::query()->withoutGlobalScopes()->whereIn('asn_line_id', $asn->lines()->pluck('id'))->get();
        if ($units->isEmpty() || $units->contains(fn (StockUnit $u) => ! $u->putaway_completed)) {
            return;
        }
        $linesPending = $asn->lines()->where('received_cartons', 0)->where('damaged_cartons', 0)->exists();
        if ($linesPending) {
            return;
        }

        $asn->update(['status' => 'putaway', 'putaway_completed_at' => now()]);
        $container = $asn->containers()->first();
        $pallets = $units->where('unit_type', 'pallet');

        $this->outbox->publish(new AsnPutawayCompleted([
            'asn_id' => $asn->id,
            'asn_no' => $asn->asn_no,
            'job_id' => $asn->job_id,
            'client_id' => $asn->client_id,
            'warehouse_id' => $asn->warehouse_id,
            'inbound_type' => $asn->inbound_type,
            'container' => $container ? ['container_id' => $container->id, 'container_no' => $container->container_no, 'size' => $container->size, 'unpack_mode' => $container->unpack_mode, 'gross_weight_kg' => $container->gross_weight_kg !== null ? (float) $container->gross_weight_kg : null, 'line_count' => $container->line_count] : null,
            'pallet_count' => $pallets->count(),
            'pallets' => $pallets->map(fn (StockUnit $u) => ['stock_unit_id' => $u->id, 'pallet_source' => $u->pallet_source, 'pallet_class' => $u->pallet_class, 'length_mm' => $u->length_mm, 'width_mm' => $u->width_mm, 'height_mm' => $u->height_mm, 'weight_kg' => $u->weight_kg !== null ? (float) $u->weight_kg : null, 'carton_qty' => $u->qty_on_hand])->values()->all(),
            'carton_unit_count' => $units->where('unit_type', 'carton')->count(),
            'label_count' => $units->count(),
            'lines' => $asn->lines()->get()->map(fn ($l) => ['asn_line_id' => $l->id, 'expected_cartons' => $l->expected_cartons, 'received_cartons' => $l->received_cartons, 'damaged_cartons' => $l->damaged_cartons])->all(),
            'completed_by' => auth()->id(),
            'completed_at' => now()->toIso8601String(),
        ], jobId: $asn->job_id, clientId: $asn->client_id, correlationId: $asn->asn_no));
    }
}
