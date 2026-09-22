<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Models\Stocktake;
use App\Modules\Warehouse\Models\StocktakeLine;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Contracts\ExceptionService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * B10b stocktake (WMS-4): count by scan or by hand, review variances, close = adjust with a mandatory reason.
 *
 * CHANGE_REQUESTS #142 (lead decision 2026-09-22): a short pick with reason 找不到 freezes the shortfall on the unit (`stock_units.qty_frozen`,
 * out of available) and appends the unit to the warehouse's open 差异盘点 (`kind = discrepancy`, one per warehouse, created on first use).
 * Counting resolves the frozen quantity: counted ≥ expected → released to available at once (count()); counted < expected → the close
 * applies the variance as the usual `adjust` and clears the frozen quantity. Any stocktake line on a frozen unit resolves it the same way.
 */
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
                'kind' => Stocktake::KIND_FULL,
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

    /**
     * 找不到 (CHANGE_REQUESTS #142): the unit joins the warehouse's open 差异盘点 as one line with the unit's current on-hand as the expected
     * figure — the frozen cartons are part of it. A unit already on that stocktake is reset to "not counted" so it is counted again.
     * Runs inside the caller's transaction (OutboundService::confirmPick); the open stocktake row is locked so two pickers share one.
     */
    public function addDiscrepancyLine(StockUnit $unit, ?int $userId = null): Stocktake
    {
        $stocktake = Stocktake::query()->where('warehouse_id', $unit->warehouse_id)->where('kind', Stocktake::KIND_DISCREPANCY)->where('status', 'counting')
            ->orderBy('id')->lockForUpdate()->first();
        if ($stocktake === null) {
            $stocktake = Stocktake::query()->create([
                'stocktake_no' => DocumentNumbers::next(Stocktake::query(), 'stocktake_no', 'STK'),
                'warehouse_id' => $unit->warehouse_id,
                'status' => 'counting',
                'kind' => Stocktake::KIND_DISCREPANCY,
                'started_by' => $userId ?? auth()->id(),
            ]);
        }

        $line = $stocktake->lines()->where('stock_unit_id', $unit->id)->first();
        if ($line === null) {
            $stocktake->lines()->create(['stock_unit_id' => $unit->id, 'expected_qty' => $unit->qty_on_hand]);
        } else {
            $line->update(['expected_qty' => $unit->qty_on_hand, 'counted_qty' => null, 'variance' => null, 'reason' => null, 'scanned' => false, 'counted_by' => null, 'counted_at' => null]);
        }

        return $stocktake;
    }

    public function count(StocktakeLine $line, int $countedQty, ?string $reason = null, bool $scanned = false): StocktakeLine
    {
        if ($line->stocktake->status !== 'counting') {
            throw new RuleViolation('This stocktake is no longer counting.', 'warehouse.stocktakes.errors.not_counting');
        }

        return DB::transaction(function () use ($line, $countedQty, $reason, $scanned): StocktakeLine {
            $line->update([
                'counted_qty' => $countedQty,
                'variance' => $countedQty - $line->expected_qty,
                'reason' => $reason,
                'scanned' => $scanned,
                'counted_by' => auth()->id(),
                'counted_at' => now(),
            ]);

            // #142: everything expected is there → the 找不到 cartons were found; they go back to available now, not at the close.
            $unit = StockUnit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->stock_unit_id);
            if ($unit->qty_frozen > 0 && $countedQty >= $line->expected_qty) {
                $unit->update(['qty_frozen' => 0]);
            }

            return $line->fresh();
        });
    }

    /** Closing applies every variance as a ledger `adjust`; a variance without a reason blocks the close (§4.4). Frozen quantities on counted units are cleared (#142). */
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
            foreach ($lines->filter(fn (StocktakeLine $l) => $l->variance !== 0 || $l->stockUnit->qty_frozen > 0) as $line) {
                $unit = StockUnit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->stock_unit_id);
                if ($line->variance !== 0) {
                    $this->ledger->record($unit, 'adjust', $line->variance, ['source_type' => 'stocktake', 'source_id' => $stocktake->id]);
                    $line->update(['adjusted_at' => now()]);
                    $variances++;
                }
                if ($unit->qty_frozen > 0) {
                    // #142: the count is the truth now — what was short is adjusted off above, so nothing stays frozen.
                    $unit->update(['qty_frozen' => 0]);
                }
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
