<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * CHANGE_REQUESTS #169 托盘管理: the pallet pool. An emptied pallet is cleared from its slot (release → free: no location, no units, no
 * client); receiving takes a free pallet of the warehouse before printing a new number (reassign); a unit can move to another pallet or
 * come off its pallet (repalletise); a pallet's source / class / dims can be corrected after receiving (update).
 */
final class PalletService
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * The spec the receiving form pre-fills for a pallet source (config/erp.php pallet_specs): the pallet's footprint / tare, corrected
     * by the operator when the loaded pallet differs.
     *
     * @return array{length_mm:int, width_mm:int, height_mm:int, weight_kg:float}|null
     */
    public static function spec(?string $source): ?array
    {
        $spec = config('erp.pallet_specs.'.($source ?? ''));

        return is_array($spec) ? $spec : null;
    }

    /** Clear an emptied pallet from its slot into the free pool: the slot is really free again, the zero-carton units come off it. */
    public function release(Pallet $pallet): Pallet
    {
        return DB::transaction(function () use ($pallet): Pallet {
            $pallet = Pallet::query()->lockForUpdate()->findOrFail($pallet->id);
            if (StockUnit::query()->withoutGlobalScopes()->where('pallet_id', $pallet->id)->where(fn ($q) => $q->where('qty_on_hand', '>', 0)->orWhere('qty_reserved', '>', 0))->exists()) {
                throw new RuleViolation("Pallet {$pallet->pallet_no} still carries stock.", 'warehouse.pallets.errors.not_empty', ['no' => $pallet->pallet_no]);
            }
            StockUnit::query()->withoutGlobalScopes()->where('pallet_id', $pallet->id)->update(['pallet_id' => null]);
            $pallet->update(['status' => 'empty', 'location_id' => null, 'putaway_completed' => false, 'released_at' => now()]);

            return $pallet->fresh();
        });
    }

    /**
     * Put a free pallet back to work for an ASN's goods: it takes the client / Job / warehouse, sits at the receiving dock, and its
     * class / source / dims come from the receiving spec (the source's default spec fills what the spec leaves blank).
     *
     * @param  array<string, mixed>  $spec
     */
    public function reassign(Pallet $pallet, Asn $asn, array $spec, ?string $palletClass, ?string $suggested, Location $receivingLocation): Pallet
    {
        if (! $pallet->isFree()) {
            throw new RuleViolation("Pallet {$pallet->pallet_no} is not free.", 'warehouse.receiving.errors.pallet_unusable', ['pallet' => $pallet->pallet_no]);
        }
        $source = $spec['pallet_source'] ?? $pallet->pallet_source ?? 'warehouse_plain';
        $defaults = self::spec($source) ?? [];
        $pallet->update([
            'warehouse_id' => $asn->warehouse_id, 'billing_warehouse_id' => $asn->warehouse_id, 'client_id' => $asn->client_id, 'job_id' => $asn->job_id,
            'location_id' => $receivingLocation->id, 'pallet_class' => $palletClass, 'pallet_class_overridden_reason' => $palletClass !== $suggested ? ($spec['pallet_class_reason'] ?? 'overridden at receiving') : null,
            'pallet_source' => $source,
            'length_mm' => $spec['length_mm'] ?? $defaults['length_mm'] ?? null, 'width_mm' => $spec['width_mm'] ?? $defaults['width_mm'] ?? null,
            'height_mm' => $spec['height_mm'] ?? $defaults['height_mm'] ?? null, 'weight_kg' => $spec['weight_kg'] ?? $defaults['weight_kg'] ?? null,
            'status' => 'in_use', 'putaway_completed' => false, 'received_at' => now(), 'released_at' => null, 'reuse_count' => $pallet->reuse_count + 1,
        ]);

        return $pallet->fresh();
    }

    /**
     * Move a unit onto another pallet of the same client + Job + warehouse (the unit takes the target's location; the target may be a free
     * pallet, which then joins the unit's location), or take it off its pallet (it stays where it is, loose). Both pallets' status follow.
     */
    public function repalletise(StockUnit $unit, ?Pallet $target, ?int $userId = null): StockUnit
    {
        return DB::transaction(function () use ($unit, $target, $userId): StockUnit {
            $unit = StockUnit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($unit->id);
            if ($unit->qty_on_hand <= 0) {
                throw new RuleViolation("{$unit->label_code} has no cartons.", 'warehouse.pallets.errors.unit_empty', ['label' => $unit->label_code]);
            }
            $from = $unit->pallet_id !== null ? Pallet::query()->lockForUpdate()->find($unit->pallet_id) : null;
            if ($target !== null) {
                $target = Pallet::query()->lockForUpdate()->findOrFail($target->id);
                if ($target->id === $from?->id) {
                    return $unit;
                }
                if ($target->isFree()) {
                    // A free pallet is brought to the unit: it takes the unit's client / Job / warehouse and location.
                    $target->update(['warehouse_id' => $unit->warehouse_id, 'billing_warehouse_id' => $unit->billing_warehouse_id ?? $unit->warehouse_id, 'client_id' => $unit->client_id, 'job_id' => $unit->job_id,
                        'location_id' => $unit->location_id, 'status' => 'in_use', 'putaway_completed' => $unit->putaway_completed, 'received_at' => now(), 'released_at' => null, 'reuse_count' => $target->reuse_count + 1]);
                } elseif ((int) $target->client_id !== (int) $unit->client_id || (int) $target->job_id !== (int) $unit->job_id || (int) $target->warehouse_id !== (int) $unit->warehouse_id) {
                    throw new RuleViolation("Pallet {$target->pallet_no} belongs to another client / Job / warehouse.", 'warehouse.pallets.errors.other_owner', ['no' => $target->pallet_no]);
                }
                if ($target->location_id !== null && (int) $target->location_id !== (int) $unit->location_id) {
                    $this->ledger->record($unit, 'transfer', 0, ['from_location_id' => $unit->location_id, 'to_location_id' => $target->location_id, 'source_type' => 'repalletise', 'source_id' => $target->id, 'operator_id' => $userId]);
                }
                $unit->update(['pallet_id' => $target->id, 'putaway_completed' => $target->putaway_completed]);
                $target->refreshStatus();
            } else {
                if ($from === null) {
                    return $unit;
                }
                $unit->update(['pallet_id' => null]);
            }
            $from?->refreshStatus();

            return $unit->fresh();
        });
    }

    /**
     * Correct a pallet after receiving: source / class (+ reason) / dims / weight; the units on it keep matching copies.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Pallet $pallet, array $attributes): Pallet
    {
        return DB::transaction(function () use ($pallet, $attributes): Pallet {
            $pallet->update(array_intersect_key($attributes, array_flip(['pallet_source', 'pallet_class', 'pallet_class_overridden_reason', 'length_mm', 'width_mm', 'height_mm', 'weight_kg'])));
            StockUnit::query()->withoutGlobalScopes()->where('pallet_id', $pallet->id)->where('unit_type', 'pallet')
                ->update(array_intersect_key($attributes, array_flip(['pallet_source', 'pallet_class', 'pallet_class_overridden_reason'])));

            return $pallet->fresh();
        });
    }
}
