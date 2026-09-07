<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\ExceptionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * B10b damage / quarantine (WMS-8): mark with a reason and photos, move out of available stock into a quarantine
 * location; storage is still billed (§4.8). Reserved stock must be released by the coordinator first.
 */
final class QuarantineService
{
    public function __construct(
        private readonly MoveService $moves,
        private readonly DocumentService $documents,
        private readonly ExceptionService $exceptions,
    ) {}

    /** @param list<array{path:string, original_name?:string, mime?:string, size_bytes?:int}> $photos */
    public function quarantine(StockUnit $unit, string $condition, string $reason, array $photos = [], ?Location $to = null): StockUnit
    {
        if (! in_array($condition, ['damaged', 'quarantine'], true)) {
            throw new InvalidArgumentException("Condition must be damaged or quarantine, got {$condition}.");
        }
        if ($unit->qty_reserved > 0) {
            throw new InvalidArgumentException("{$unit->label_code} has {$unit->qty_reserved} cartons reserved — release the reservations before quarantining.");
        }

        return DB::transaction(function () use ($unit, $condition, $reason, $photos, $to): StockUnit {
            $unit->update(['condition' => $condition, 'condition_reason' => $reason, 'condition_changed_at' => now()]);

            $to ??= Location::query()->where('warehouse_id', $unit->warehouse_id)->where('type', 'quarantine')->where('active', true)->orderBy('full_code')->first();
            if ($to !== null) {
                $unit = $this->moves->move($unit->fresh(), $to, $reason);
            }

            foreach ($photos as $photo) {
                $this->documents->attach('photo', 'stock_unit', $unit->id, $photo['path'], [
                    'job_id' => $unit->job_id, 'client_id' => $unit->client_id, 'client_visible' => false,
                    'original_name' => $photo['original_name'] ?? null, 'mime' => $photo['mime'] ?? null, 'size_bytes' => $photo['size_bytes'] ?? null,
                ]);
            }

            $this->exceptions->raise('discrepancy', 'warehouse', [
                'job_id' => $unit->job_id, 'client_id' => $unit->client_id, 'source_type' => 'stock_unit', 'source_id' => $unit->id,
                'message' => "{$unit->label_code} marked {$condition}: {$reason}",
            ]);

            return $unit->fresh();
        });
    }

    public function restore(StockUnit $unit, Location $to, string $reason): StockUnit
    {
        return DB::transaction(function () use ($unit, $to, $reason): StockUnit {
            $unit->update(['condition' => 'good', 'condition_reason' => $reason, 'condition_changed_at' => now()]);

            return $this->moves->move($unit->fresh(), $to);
        });
    }
}
