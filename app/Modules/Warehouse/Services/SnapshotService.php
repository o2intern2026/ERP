<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\StockSnapshot;
use App\Modules\Warehouse\Models\StockUnit;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * B10a daily snapshot (ERP_PLAN §4.4 每日快照, §4.8): every unit with stock, classified by pallet type, pallet source
 * and pickface occupancy. Re-running for the same day replaces that day's rows (idempotent cron).
 */
final class SnapshotService
{
    public function take(?CarbonInterface $date = null): int
    {
        $date = ($date ?? today())->toDateString();

        return DB::transaction(function () use ($date): int {
            StockSnapshot::query()->withoutGlobalScopes()->where('snapshot_date', $date)->delete();

            $rows = StockUnit::query()->withoutGlobalScopes()->with('location')->where('qty_on_hand', '>', 0)->get()
                ->map(fn (StockUnit $u) => [
                    'snapshot_date' => $date,
                    'warehouse_id' => $u->warehouse_id,
                    'client_id' => $u->client_id,
                    'job_id' => $u->job_id,
                    'stock_unit_id' => $u->id,
                    'asn_line_id' => $u->asn_line_id,
                    'unit_type' => $u->unit_type,
                    'pallet_class' => $u->pallet_class,
                    'pallet_source' => $u->pallet_source,
                    'location_id' => $u->location_id,
                    'location_type' => $u->location?->type,
                    'condition' => $u->condition,
                    'qty_on_hand' => $u->qty_on_hand,
                    'qty_reserved' => $u->qty_reserved,
                    'created_at' => now(),
                ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                StockSnapshot::query()->insert($chunk);
            }

            return count($rows);
        });
    }

    /**
     * Per client for one day: pallets by class and source, carton units, pickface slots occupied (distinct locations).
     *
     * @return Collection<int, array{client_id:int, warehouse_id:int, pallets:int, pallets_by_class:array<string,int>, pallets_by_source:array<string,int>, carton_units:int, cartons:int, pickface_slots:int, damaged_units:int}>
     */
    public function summary(CarbonInterface $date): Collection
    {
        return StockSnapshot::query()->where('snapshot_date', $date->toDateString())->get()
            ->groupBy(fn (StockSnapshot $s) => $s->client_id.'-'.$s->warehouse_id)
            ->map(function (Collection $rows) {
                $pallets = $rows->where('unit_type', 'pallet');

                return [
                    'client_id' => (int) $rows->first()->client_id,
                    'warehouse_id' => (int) $rows->first()->warehouse_id,
                    'pallets' => $pallets->count(),
                    'pallets_by_class' => $pallets->groupBy(fn ($s) => $s->pallet_class ?? 'poa')->map->count()->all(),
                    'pallets_by_source' => $pallets->groupBy(fn ($s) => $s->pallet_source ?? 'unknown')->map->count()->all(),
                    'carton_units' => $rows->where('unit_type', 'carton')->count(),
                    'cartons' => (int) $rows->sum('qty_on_hand'),
                    'pickface_slots' => $rows->where('location_type', 'pickface')->pluck('location_id')->unique()->count(),
                    'damaged_units' => $rows->where('condition', '!=', 'good')->count(),
                ];
            })->values();
    }
}
