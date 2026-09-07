<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Warehouse configuration: locations (four-level code) per warehouse. */
class LocationController extends Controller
{
    public function index(): View
    {
        return view('warehouse::locations.index', [
            'warehouses' => Warehouse::query()->with(['locations' => fn ($q) => $q->orderBy('full_code')])->orderBy('code')->get(),
            'types' => Enums::LOCATION_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'zone' => ['required', 'string', 'max:10', 'alpha_num'],
            'aisle' => ['required', 'string', 'max:10', 'alpha_num'],
            'bin' => ['required', 'string', 'max:10', 'alpha_num'],
            'type' => ['required', Rule::in(Enums::LOCATION_TYPES)],
        ]);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $fullCode = Location::buildFullCode($warehouse->code, $data['zone'], $data['aisle'], $data['bin']);

        Location::query()->firstOrCreate(['warehouse_id' => $warehouse->id, 'full_code' => $fullCode], $data + ['active' => true]);

        return back()->with('status', __('warehouse.locations.created', ['code' => $fullCode]));
    }
}
