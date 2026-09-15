<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** B2 putaway: receiving area → storage / pickface / quarantine; scan-gun friendly (location code input). */
class PutawayController extends Controller
{
    public function index(Request $request): View
    {
        $units = StockUnit::query()->with(['asnLine.asn.client', 'location', 'warehouse'])->where('putaway_completed', false)
            ->when(WarehouseContext::currentId(), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->orderBy('id')->paginate(50);

        return view('warehouse::putaway.index', [
            'units' => $units,
            'highlight' => $request->integer('highlight') ?: null,
            'locations' => Location::query()->where('active', true)->whereIn('type', ['storage', 'pickface', 'quarantine'])->orderBy('full_code')->get()->groupBy('warehouse_id'),
            'bottomHints' => $this->freeBottomLocations($units->getCollection()),
        ]);
    }

    public function store(Request $request, StockUnit $unit, PutawayService $putaway): RedirectResponse
    {
        $data = $request->validate(['location_code' => ['required', 'string', 'max:40'], 'tier_reason' => ['nullable', 'string', 'max:255']]);

        $location = Location::query()->where('warehouse_id', $unit->warehouse_id)->where('full_code', strtoupper(trim($data['location_code'])))->first();
        if ($location === null) {
            return back()->withErrors(['location_code' => __('warehouse.putaway.unknown_location', ['code' => $data['location_code']])])->withInput(['location_code' => $data['location_code'], 'putaway_unit' => $unit->id]);
        }

        try {
            $putaway->putaway($unit, $location, $data['tier_reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            // #126: a tier refusal re-opens the row with a reason input next to the scanned code.
            $tierRefused = $e instanceof RuleViolation && $e->langKey() === 'warehouse.putaway.errors.tier_mismatch';

            return back()->withErrors(['location_code' => RuleViolation::display($e)])->withInput(['location_code' => $data['location_code'], 'putaway_unit' => $unit->id, 'tier_refused' => $tierRefused ? 1 : 0]);
        }

        $status = __('warehouse.putaway.done', ['label' => $unit->label_code, 'location' => $location->full_code]);
        if (PutawayService::standardIntoBottom($unit, $location)) {
            $status .= ' '.__('warehouse.putaway.standard_into_bottom', ['code' => $location->full_code]);
        }

        return back()->with('status', $status);
    }

    /**
     * #126 建议 for bottom pallets: per warehouse on the page, the first active bottom storage location (by full_code) that holds no
     * stock unit with qty_on_hand > 0. A hint only — the supervisor still chooses; no reservation of locations (lead answer 5).
     *
     * @param  iterable<StockUnit>  $units
     * @return array<int, string> warehouse id → location code
     */
    private function freeBottomLocations(iterable $units): array
    {
        $warehouseIds = collect($units)->filter(fn (StockUnit $u) => $u->unit_type === 'pallet' && $u->required_storage_tier === 'bottom')->pluck('warehouse_id')->unique();

        return $warehouseIds->mapWithKeys(fn ($warehouseId) => [(int) $warehouseId => Location::query()
            ->where('warehouse_id', $warehouseId)->where('active', true)->where('type', 'storage')->where('storage_tier', 'bottom')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('stock_units')->whereColumn('stock_units.location_id', 'locations.id')->where('stock_units.qty_on_hand', '>', 0))
            ->orderBy('full_code')->value('full_code')])->filter()->all();
    }
}
