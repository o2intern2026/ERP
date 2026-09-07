<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\RateService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** B10b / B14: location-to-location moves, including across warehouses; every move is a ledger `transfer` row. */
final class MoveService
{
    public function __construct(private readonly StockLedger $ledger, private readonly RateService $rates) {}

    public function move(StockUnit $unit, Location $to, ?string $reason = null): StockUnit
    {
        if (! $to->active || ! in_array($to->type, ['storage', 'pickface', 'quarantine', 'staging'], true)) {
            throw new InvalidArgumentException("Location {$to->full_code} cannot hold stock.");
        }
        if ($unit->condition !== 'good' && $to->type !== 'quarantine') {
            throw new InvalidArgumentException('Damaged / quarantined stock may only be moved between quarantine locations.');
        }
        if ($to->id === $unit->location_id) {
            return $unit;
        }

        return DB::transaction(function () use ($unit, $to, $reason): StockUnit {
            $from = $unit->location;
            $this->ledger->record($unit, 'transfer', 0, ['from_location_id' => $unit->location_id, 'to_location_id' => $to->id, 'source_type' => 'move', 'source_id' => null]);

            $unit->warehouse_id = $to->warehouse_id; // cross-warehouse move (B14)
            if ($to->type === 'pickface') {
                $unit->pallet_class = 'pickface';
            } elseif ($from?->type === 'pickface' && $unit->unit_type === 'pallet') {
                $unit->pallet_class = $unit->length_mm && $unit->width_mm && $unit->height_mm && $unit->weight_kg !== null
                    ? $this->rates->suggestPalletClass($unit->client_id, $unit->length_mm, $unit->width_mm, $unit->height_mm, (float) $unit->weight_kg)
                    : null;
            }
            if ($reason !== null) {
                $unit->condition_reason = $unit->condition === 'good' ? $unit->condition_reason : $reason;
            }
            $unit->save();

            return $unit->fresh();
        });
    }
}
