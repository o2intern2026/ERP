<?php

namespace App\Modules\Warehouse\Console;

use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\StockLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Ledger is the truth: compare every stock unit's balances with its ledger and active reservations (ERP_PLAN §4.3 rule 9). */
final class ReconcileStockCommand extends Command
{
    protected $signature = 'stock:reconcile {--fix : Rewrite qty_on_hand / qty_reserved from the ledger and reservations}';

    protected $description = 'Check stock_units balances against stock_ledger and stock_reservations';

    public function handle(): int
    {
        $ledgerSums = DB::table('stock_ledger')->select('stock_unit_id', DB::raw('SUM(qty) as qty'))
            ->whereIn('movement_type', StockLedger::ON_HAND_TYPES)->groupBy('stock_unit_id')->pluck('qty', 'stock_unit_id');
        $reserved = DB::table('stock_reservations')->select('stock_unit_id', DB::raw('SUM(qty) as qty'))
            ->where('status', 'active')->groupBy('stock_unit_id')->pluck('qty', 'stock_unit_id');

        $differences = 0;
        StockUnit::query()->withoutGlobalScopes()->orderBy('id')->chunk(500, function ($units) use ($ledgerSums, $reserved, &$differences) {
            foreach ($units as $unit) {
                $expectedOnHand = (int) ($ledgerSums[$unit->id] ?? 0);
                $expectedReserved = (int) ($reserved[$unit->id] ?? 0);
                if ($unit->qty_on_hand !== $expectedOnHand || $unit->qty_reserved !== $expectedReserved) {
                    $differences++;
                    $this->warn(sprintf('%s: on_hand %d (ledger %d), reserved %d (reservations %d)', $unit->label_code, $unit->qty_on_hand, $expectedOnHand, $unit->qty_reserved, $expectedReserved));
                    if ($this->option('fix')) {
                        $unit->update(['qty_on_hand' => $expectedOnHand, 'qty_reserved' => $expectedReserved]);
                    }
                }
            }
        });

        $this->info($differences === 0 ? 'stock:reconcile — no differences' : "stock:reconcile — {$differences} unit(s) differ".($this->option('fix') ? ' (fixed)' : ''));

        return $differences === 0 || $this->option('fix') ? self::SUCCESS : self::FAILURE;
    }
}
