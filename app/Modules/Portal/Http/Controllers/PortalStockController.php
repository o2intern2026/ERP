<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Portal\Http\PortalValidation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ERP_PLAN §7 step 8 / §3.8 #5 "自查库存": the client's own stock, grouped by goods line (consignment mark + description),
 * location type and condition — cartons on hand, reserved and available (= on hand − reserved, only once put away, §4.3 rules 1–2).
 * Read-only join over Warehouse's `stock_units` → `asn_lines` → `asns` with the signed-in client's id as an explicit predicate
 * (the same pattern as the A21 client report); nothing here is written and no cost field exists on these tables.
 */
final class PortalStockController extends Controller
{
    /** Item 1 (tester feedback): 货物状态 filter — good, or abnormal = quarantine + damaged (stock_units.condition, enums.md §4). */
    public const CONDITION_FILTERS = ['good', 'abnormal'];

    /** Optional 可用性 filter on the computed available quantity of each grouped row. */
    public const AVAILABILITY_FILTERS = ['available', 'none'];

    public function index(Request $request): View
    {
        $clientId = $this->clientId($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'condition' => ['nullable', Rule::in(self::CONDITION_FILTERS)],
            'availability' => ['nullable', Rule::in(self::AVAILABILITY_FILTERS)],
        ], PortalValidation::messages(), PortalValidation::attributes()); // Chinese field names for a hand-edited filter URL

        $rows = DB::table('stock_units as u')
            ->join('asn_lines as l', 'l.id', '=', 'u.asn_line_id')
            ->join('asns as a', 'a.id', '=', 'l.asn_id')
            ->leftJoin('locations as loc', 'loc.id', '=', 'u.location_id')
            ->where('u.client_id', $clientId)
            ->where(fn ($w) => $w->where('u.qty_on_hand', '>', 0)->orWhere('u.qty_inbound', '>', 0))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($w) => $w
                ->where('l.consignment_mark', 'like', "%{$q}%")
                ->orWhere('l.description', 'like', "%{$q}%")
                ->orWhere('a.asn_no', 'like', "%{$q}%")
                ->orWhere('l.fba_reference', 'like', "%{$q}%")))
            ->when(($filters['condition'] ?? null) === 'good', fn ($query) => $query->where('u.condition', 'good'))
            ->when(($filters['condition'] ?? null) === 'abnormal', fn ($query) => $query->whereIn('u.condition', ['quarantine', 'damaged']))
            ->groupBy('l.id', 'l.consignment_mark', 'l.description', 'l.fba_reference', 'a.asn_no', 'loc.type', 'u.condition', 'u.putaway_completed')
            ->orderBy('l.consignment_mark')->orderBy('l.id')->orderBy('loc.type')->orderBy('u.condition')
            ->get([
                'l.id as asn_line_id', 'l.consignment_mark', 'l.description', 'l.fba_reference', 'a.asn_no', 'loc.type as location_type', 'u.condition', 'u.putaway_completed',
                DB::raw('count(*) as units'),
                DB::raw("sum(case when u.unit_type = 'pallet' then 1 else 0 end) as pallets"),
                DB::raw('coalesce(sum(u.qty_on_hand), 0) as qty_on_hand'),
                DB::raw('coalesce(sum(u.qty_reserved), 0) as qty_reserved'),
                DB::raw('coalesce(sum(u.qty_inbound), 0) as qty_inbound'),
            ])
            ->map(function ($row): object {
                $row->qty_on_hand = (int) $row->qty_on_hand;
                $row->qty_reserved = (int) $row->qty_reserved;
                $row->qty_inbound = (int) $row->qty_inbound;
                $row->units = (int) $row->units;
                $row->pallets = (int) $row->pallets;
                $row->putaway_completed = (bool) $row->putaway_completed;
                // Only put-away, good-condition stock is available to orders (§4.3 rules 1–2); the rest is shown but counts as 0 available.
                $row->qty_available = $row->putaway_completed && $row->condition === 'good' ? max(0, $row->qty_on_hand - $row->qty_reserved) : 0;

                return $row;
            })
            ->filter(fn ($row) => match ($filters['availability'] ?? null) { // availability is derived per row, so it is filtered after grouping
                'available' => $row->qty_available > 0,
                'none' => $row->qty_available === 0,
                default => true,
            })
            ->values();

        return view('portal::stock.index', [
            'rows' => $rows,
            'filters' => $filters,
            'conditionFilters' => self::CONDITION_FILTERS,
            'availabilityFilters' => self::AVAILABILITY_FILTERS,
            'totals' => [
                'units' => $rows->sum('units'),
                'pallets' => $rows->sum('pallets'),
                'qty_on_hand' => $rows->sum('qty_on_hand'),
                'qty_reserved' => $rows->sum('qty_reserved'),
                'qty_available' => $rows->sum('qty_available'),
                'qty_inbound' => $rows->sum('qty_inbound'),
            ],
        ]);
    }

    private function clientId(Request $request): int
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));

        return (int) $user->client_id;
    }
}
