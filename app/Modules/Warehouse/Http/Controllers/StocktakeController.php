<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Stocktake;
use App\Modules\Warehouse\Models\StocktakeLine;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\StocktakeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** B10b stocktake pages: open by scope, count by hand or by scanning the unit label, close with reasons. */
class StocktakeController extends Controller
{
    public function index(): View
    {
        return view('warehouse::stocktakes.index', [
            'stocktakes' => Stocktake::query()->with(['warehouse', 'location'])->withCount('lines')->orderByDesc('id')->paginate(30),
        ]);
    }

    public function create(): View
    {
        return view('warehouse::stocktakes.create', [
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, StocktakeService $service): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'location_code' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $locationId = null;
        if (! empty($data['location_code'])) {
            $location = Location::query()->where('warehouse_id', $data['warehouse_id'])->where('full_code', strtoupper(trim($data['location_code'])))->first();
            if ($location === null) {
                return back()->withErrors(['location_code' => __('warehouse.putaway.unknown_location', ['code' => $data['location_code']])])->withInput();
            }
            $locationId = $location->id;
        }

        $stocktake = $service->open(['warehouse_id' => (int) $data['warehouse_id'], 'client_id' => $data['client_id'] ?? null, 'location_id' => $locationId, 'notes' => $data['notes'] ?? null]);

        return redirect()->route('warehouse.stocktakes.show', $stocktake)->with('status', __('warehouse.stocktakes.opened', ['no' => $stocktake->stocktake_no, 'lines' => $stocktake->lines()->count()]));
    }

    public function show(Stocktake $stocktake): View
    {
        return view('warehouse::stocktakes.show', [
            'stocktake' => $stocktake->load(['warehouse', 'location']),
            'lines' => $stocktake->lines()->with(['stockUnit.location', 'stockUnit.asnLine.asn.client'])->orderBy('id')->get(),
        ]);
    }

    public function count(Request $request, Stocktake $stocktake, StocktakeLine $line, StocktakeService $service): RedirectResponse
    {
        abort_unless($line->stocktake_id === $stocktake->id, 404);
        $data = $request->validate(['counted_qty' => ['required', 'integer', 'min:0'], 'reason' => ['nullable', 'string', 'max:255']]);

        try {
            $service->count($line, (int) $data['counted_qty'], $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['counted_qty' => $e->getMessage()]);
        }

        return back()->with('status', __('warehouse.stocktakes.counted', ['label' => $line->stockUnit->label_code]));
    }

    /** Scan gun / camera: the unit label confirms presence; quantity defaults to expected unless typed. */
    public function scan(Request $request, Stocktake $stocktake, StocktakeService $service): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:60'], 'counted_qty' => ['nullable', 'integer', 'min:0']]);

        $line = $stocktake->lines()->whereHas('stockUnit', fn ($q) => $q->where('label_code', strtoupper(trim($data['code']))))->first();
        if ($line === null) {
            return back()->withErrors(['code' => __('warehouse.stocktakes.unknown_code', ['code' => $data['code']])]);
        }

        try {
            $service->count($line, (int) ($data['counted_qty'] ?? $line->expected_qty), null, true);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('status', __('warehouse.stocktakes.scanned', ['label' => $line->stockUnit->label_code]));
    }

    public function close(Stocktake $stocktake, StocktakeService $service): RedirectResponse
    {
        try {
            $service->close($stocktake);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', __('warehouse.stocktakes.closed'));
    }
}
