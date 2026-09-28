<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\RateService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Support\Facades\DB;

/** B10b 移库: a unit — or, since CHANGE_REQUESTS #166, the whole pallet it sits on — to another location that can hold stock; every unit gets a ledger line. */
final class MoveService
{
    public function __construct(private readonly StockLedger $ledger, private readonly RateService $rates) {}

    public function move(StockUnit $unit, Location $to, ?string $reason = null): StockUnit
    {
        if (! $to->active || ! in_array($to->type, ['storage', 'pickface', 'quarantine', 'staging'], true)) {
            throw new RuleViolation("Location {$to->full_code} cannot hold stock.", 'warehouse.moves.errors.cannot_hold', ['code' => $to->full_code]);
        }
        // CHANGE_REQUESTS #166: moving one unit of a pallet moves the pallet — every unit on it, same checks for each.
        $units = $unit->pallet_id !== null
            ? StockUnit::query()->withoutGlobalScopes()->where('pallet_id', $unit->pallet_id)->orderBy('id')->get()
            : collect([$unit]);
        foreach ($units as $each) {
            if ($each->condition !== 'good' && $to->type !== 'quarantine') {
                throw new RuleViolation('Damaged / quarantined stock may only be moved between quarantine locations.', 'warehouse.moves.errors.held_needs_quarantine');
            }
        }
        if ($to->id === $unit->location_id) {
            return $unit;
        }

        return DB::transaction(function () use ($unit, $units, $to, $reason): StockUnit {
            $from = $unit->location;
            foreach ($units as $each) {
                $this->ledger->record($each, 'transfer', 0, ['from_location_id' => $each->location_id, 'to_location_id' => $to->id, 'source_type' => 'move', 'source_id' => null, 'operator_id' => auth()->id()]);
                $each->warehouse_id = $to->warehouse_id; // cross-warehouse move (B14)
                if ($to->type === 'pickface') {
                    $each->pallet_class = 'pickface';
                } elseif ($from?->type === 'pickface' && $each->unit_type === 'pallet') {
                    $each->pallet_class = $this->suggestedClass($each);
                }
                if ($reason !== null) {
                    $each->condition_reason = $each->condition === 'good' ? $each->condition_reason : $reason;
                }
                $each->save();
            }
            if ($unit->pallet_id !== null) {
                $pallet = Pallet::query()->whereKey($unit->pallet_id)->first();
                if ($pallet !== null) {
                    $class = $to->type === 'pickface' ? 'pickface' : ($from?->type === 'pickface' ? $this->suggestedClass($pallet) : $pallet->pallet_class);
                    $pallet->update(['location_id' => $to->id, 'warehouse_id' => $to->warehouse_id, 'pallet_class' => $class]);
                }
            }

            return $unit->fresh();
        });
    }

    /** The rate card's class for the measured dims / weight (unit or pallet), null when a measurement is missing. */
    private function suggestedClass(StockUnit|Pallet $measured): ?string
    {
        return $measured->length_mm && $measured->width_mm && $measured->height_mm && $measured->weight_kg !== null
            ? $this->rates->suggestPalletClass($measured->client_id, $measured->length_mm, $measured->width_mm, $measured->height_mm, (float) $measured->weight_kg)
            : null;
    }
}
