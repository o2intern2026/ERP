<?php

namespace App\Modules\Warehouse\Console;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\PutawayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off after audit 2026-09-22 INBOUND-03: ASNs left in `receiving` although every goods line is received (a line received with 0
 * cartons counts) and every stock unit is put away. Lists them; `--apply` runs PutawayService::completeIfDone on each, which flips the
 * status and emits asn.putaway_completed (Billing raises the putaway / label charges, the 生成派送订单 button appears). Run on the
 * server once after the merge — see HANDOFF.md 2026-09-22.
 */
final class ReevaluateAsnPutawayCommand extends Command
{
    protected $signature = 'asn:reevaluate-putaway {--apply : Complete the ASNs listed (default: list only)}';

    protected $description = 'List (or, with --apply, complete) ASNs stuck in receiving although every line is received and every unit is put away';

    public function handle(PutawayService $putaway): int
    {
        $stuck = Asn::query()->withoutGlobalScopes()->where('status', 'receiving')
            ->whereHas('lines')
            ->whereDoesntHave('lines', fn ($q) => $q->whereDoesntHave('receiptLine')->whereDoesntHave('stockUnits'))
            ->whereDoesntHave('lines.stockUnits', fn ($q) => $q->where('putaway_completed', false))
            ->orderBy('id')->get();

        if ($stuck->isEmpty()) {
            $this->info('asn:reevaluate-putaway — no ASN is stuck in receiving');

            return self::SUCCESS;
        }

        $flipped = 0;
        foreach ($stuck as $asn) {
            $units = $asn->lines()->withCount('stockUnits')->get()->sum('stock_units_count');
            $this->line(sprintf('%s (id %d, client %d): %d line(s), %d unit(s), receiving_completed_at %s', $asn->asn_no, $asn->id, $asn->client_id, $asn->lines()->count(), $units, $asn->receiving_completed_at?->toDateTimeString() ?? '—'));
            if ($this->option('apply') && DB::transaction(fn () => $putaway->completeIfDone($asn))) {
                $flipped++;
                $this->info("  → {$asn->asn_no} completed: status putaway, asn.putaway_completed published");
            }
        }

        $this->info($this->option('apply') ? "asn:reevaluate-putaway — {$flipped} of {$stuck->count()} ASN(s) completed" : "asn:reevaluate-putaway — {$stuck->count()} ASN(s) stuck; re-run with --apply to complete them");

        return self::SUCCESS;
    }
}
