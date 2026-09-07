<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Warehouse\Models\StockSnapshot;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\RateService;
use Carbon\CarbonInterface;

/**
 * A6b weekly storage from the daily snapshots (ERP_PLAN §6.7 A6b, §6.8 #4 #13, §4.8): a unit that appears in any
 * snapshot of the ISO week is charged once for that week — never 7 times. Inbound week and outbound week both count.
 * Pallets: storage by pallet class (quarantine / damaged on their own code) + pallet rental by source;
 * loose cartons: carton·week or CBM·week, whichever the client card carries; pickface: occupied slots per client and job.
 */
final class StorageBillingService
{
    public function __construct(private readonly ChargeEngine $engine) {}

    /** @return list<Charge> */
    public function billWeek(CarbonInterface $anyDayInWeek): array
    {
        $start = $anyDayInWeek->copy()->startOfWeek(CarbonInterface::MONDAY);
        $end = $start->copy()->endOfWeek(CarbonInterface::SUNDAY);
        $week = $start->format('o-\WW');
        $codes = ChargeCode::query()->whereIn('code', ['WH-STORAGE-PLT-WK', 'WH-STORAGE-PLT-WIDE-WK', 'WH-STORAGE-PLT-HIGH-WK', 'WH-STORAGE-PLT-OVERWEIGHT-WK', 'WH-STORAGE-QUARANTINE-PLT-WK', 'WH-PALLET-RENT-PLAIN-WK', 'WH-PALLET-RENT-POOL-WK', 'WH-STORAGE-CTN-WK', 'WH-STORAGE-CBM-WK', 'WH-STORAGE-PICKFACE-WK'])->get()->keyBy('code');

        $snapshots = StockSnapshot::query()->withoutGlobalScopes()->whereBetween('snapshot_date', [$start->toDateString(), $end->toDateString()])->orderBy('snapshot_date')->get();
        $charges = [];

        // One row per unit for the week: the last snapshot of the week describes it (class / source / condition).
        foreach ($snapshots->groupBy('stock_unit_id') as $unitId => $rows) {
            $s = $rows->last();
            $key = "unit:{$unitId}:week:{$week}";
            $source = ['type' => 'snapshot', 'id' => (int) $unitId];

            if ($s->unit_type === 'pallet') {
                if ($s->location_type === 'pickface') {
                    // billed per slot below, not per pallet
                } elseif ($s->condition !== 'good') {
                    $charges[] = $this->engine->charge($codes['WH-STORAGE-QUARANTINE-PLT-WK'], $s->client_id, $s->job_id, 1, ['pallet_class' => $s->pallet_class], $key, 1, $source, $end);
                } else {
                    $code = match ($s->pallet_class) {
                        'oversize_wide' => 'WH-STORAGE-PLT-WIDE-WK', 'oversize_high' => 'WH-STORAGE-PLT-HIGH-WK', 'overweight' => 'WH-STORAGE-PLT-OVERWEIGHT-WK', default => 'WH-STORAGE-PLT-WK',
                    };
                    $charges[] = $this->engine->charge($codes[$code], $s->client_id, $s->job_id, 1, ['pallet_class' => $s->pallet_class ?? 'standard'], $key, 1, $source, $end);
                }
                if ($s->pallet_source === 'warehouse_plain') {
                    $charges[] = $this->engine->charge($codes['WH-PALLET-RENT-PLAIN-WK'], $s->client_id, $s->job_id, 1, [], $key, 1, $source, $end);
                } elseif (in_array($s->pallet_source, ['chep', 'loscam'], true)) {
                    $charges[] = $this->engine->charge($codes['WH-PALLET-RENT-POOL-WK'], $s->client_id, $s->job_id, 1, [], $key, 1, $source, $end);
                }
            } elseif ($s->location_type !== 'pickface') {
                // Loose cartons: per carton if the card has it, else per CBM (qty from the unit's dims), else Missing Rate once.
                $cartonProbe = app(RateService::class)->price($s->client_id, 'WH-STORAGE-CTN-WK', 1);
                if (! $cartonProbe['missing_rate']) {
                    $charges[] = $this->engine->charge($codes['WH-STORAGE-CTN-WK'], $s->client_id, $s->job_id, (float) $s->qty_on_hand, [], $key, 1, $source, $end);
                } else {
                    $unit = StockUnit::query()->withoutGlobalScopes()->find($unitId);
                    $cbm = $unit && $unit->length_mm && $unit->width_mm && $unit->height_mm ? round($unit->length_mm * $unit->width_mm * $unit->height_mm / 1e9 * max(1, $s->qty_on_hand), 4) : 0.0;
                    $charges[] = $this->engine->charge($codes['WH-STORAGE-CBM-WK'], $s->client_id, $s->job_id, $cbm > 0 ? $cbm : 1.0, [], $key, 1, $source, $end);
                }
            }
        }

        // Pickface: occupied slots per client × job for the week (distinct locations), billed once per slot.
        foreach ($snapshots->where('location_type', 'pickface')->groupBy(fn ($s) => $s->client_id.':'.$s->job_id) as $group) {
            $first = $group->first();
            $slots = $group->pluck('location_id')->unique()->count();
            $charges[] = $this->engine->charge($codes['WH-STORAGE-PICKFACE-WK'], $first->client_id, $first->job_id, $slots, [], "client:{$first->client_id}:job:{$first->job_id}:pickface:week:{$week}", 1, ['type' => 'snapshot', 'id' => null], $end);
        }

        return array_values(array_filter($charges));
    }
}
