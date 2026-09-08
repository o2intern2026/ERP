<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** B14: switch the working warehouse (session) and create warehouses. */
class WarehouseController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')]]);
        WarehouseContext::set(isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null);

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', 'alpha_dash', Rule::unique('warehouses', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:3'],
            'address' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:10'],
        ]);
        $warehouse = Warehouse::query()->create($data + ['active' => true]);

        return back()->with('status', __('warehouse.warehouses.created', ['code' => $warehouse->code]));
    }
}
