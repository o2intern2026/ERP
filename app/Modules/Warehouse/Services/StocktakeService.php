<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Stocktake;
use App\Modules\Warehouse\Models\StocktakeLine;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\ExceptionService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** B10b stocktake (WMS-4): count by scan or by hand, review variances, close = adjust with a mandatory reason. */
final class StocktakeService
{
    public function __construct(private readonly StockLedger $ledger, private readonly ExceptionService $exceptions) {}

    /** @param array{warehouse_id:int, client_id?:?int, location_id?:?int, notes?:?string} $scope */
    public function open(array $scope): Stocktake
    {
        return DB::transaction(function () use ($scope): Stocktake {
            $stocktake = Stocktake::query()->create([
                'stocktake_no' => DocumentNumbers::next(Stocktake::query(), 'stocktake_no', 'STK'),
                'warehouse_id' => $scope['warehouse_id'],
                'client_id' => $scope['client_id'] ?? null,
                'location_id' => $scope['location_id'] ?? null,
                'status' => 'counting',
                'started_by' => auth()->id(),
                'notes' => $scope['notes'] ?? null,
            ]);

            $units = StockUnit::query()->withoutGlobalScopes()
                ->where('warehouse_id', $scope['warehouse_id'])
                ->when($scope['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
                ->when($scope['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
                ->where(fn ($q) => $q->where('qty_on_hand', '>', 0)->orWhere('putaway_completed', true))
                ->orderBy('location_id')->orderBy('id')->get();

            foreach ($units as $unit) {
                $stocktake->lines()->create(['stock_unit_id' => $unit->id, 'expected_qty' => $unit->qty_on_hand]);
            }

            return $stocktake;
        });
    }

    public function count(StocktakeLine $line, int $countedQty, ?string $reason = null, bool $scanned = false): StocktakeLine
    {
        if ($line->stocktake->status !== 'counting') {
            throw new InvalidArgumentException('This stocktake is no longer counting.');
        }

        $line->update([
            'counted_qty' => $countedQty,
            'variance' => $countedQty - $line->expected_qty,
            'reason' => $reason,
            'scanned' => $scanned,
            'counted_by' => auth()->id(),
            'counted_at' => now(),
        ]);

        return $line->fresh();
    }

    /** Closing applies every variance as a ledger `adjust`; a variance without a reason blocks the close (§4.4). */
    public function close(Stocktake $stocktake): Stocktake
    {
        $lines = $stocktake->lines()->with('stockUnit')->get();

        // Audit 2026-09-10: the person reads label codes, not stocktake_lines ids.
        $uncounted = $lines->filter(fn (StocktakeLine $l) => ! $l->isCounted());
        if ($uncounted->isNotEmpty()) {
            throw new RuleViolation('Lines not counted yet: '.$uncounted->pluck('id')->implode(', '), 'warehouse.stocktakes.errors.uncounted', ['count' => $uncounted->count(), 'labels' => $uncounted->map(fn (StocktakeLine $l) => $l->stockUnit->label_code)->take(5)->implode(', ')]);
        }
        $noReason = $lines->filter(fn (StocktakeLine $l) => $l->variance !== 0 && blank($l->reason));
        if ($noReason->isNotEmpty()) {
            throw new RuleViolation('Variances need a reason on lines: '.$noReason->pluck('id')->implode(', '), 'warehouse.stocktakes.errors.reason_missing', ['count' => $noReason->count(), 'labels' => $noReason->map(fn (StocktakeLine $l) => $l->stockUnit->label_code)->take(5)->implode(', ')]);
        }

        return DB::transaction(function () use ($stocktake, $lines): Stocktake {
            $variances = 0;
            foreach ($lines->filter(fn (StocktakeLine $l) => $l->variance !== 0) as $line) {
                $unit = StockUnit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->stock_unit_id);
                $this->ledger->record($unit, 'adjust', $line->variance, ['source_type' => 'stocktake', 'source_id' => $stocktake->id]);
                $line->update(['adjusted_at' => now()]);
                $variances++;
            }

            $stocktake->update(['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now()]);

            if ($variances > 0) {
                $this->exceptions->raise('discrepancy', 'warehouse', [
                    'client_id' => $stocktake->client_id, 'source_type' => 'stocktake', 'source_id' => $stocktake->id,
                    'message' => "{$stocktake->stocktake_no}: {$variances} line(s) adjusted",
                ]);
            }

            return $stocktake->fresh();
        });
    }
}
