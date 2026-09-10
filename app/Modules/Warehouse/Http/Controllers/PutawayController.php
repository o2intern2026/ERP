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
        ]);
    }

    public function store(Request $request, StockUnit $unit, PutawayService $putaway): RedirectResponse
    {
        $data = $request->validate(['location_code' => ['required', 'string', 'max:40']]);

        $location = Location::query()->where('warehouse_id', $unit->warehouse_id)->where('full_code', strtoupper(trim($data['location_code'])))->first();
        if ($location === null) {
            return back()->withErrors(['location_code' => __('warehouse.putaway.unknown_location', ['code' => $data['location_code']])])->withInput(['location_code' => $data['location_code'], 'putaway_unit' => $unit->id]);
        }

        try {
            $putaway->putaway($unit, $location);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['location_code' => RuleViolation::display($e)])->withInput(['location_code' => $data['location_code'], 'putaway_unit' => $unit->id]);
        }

        return back()->with('status', __('warehouse.putaway.done', ['label' => $unit->label_code, 'location' => $location->full_code]));
    }
}
