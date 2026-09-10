<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockReservation;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\MoveService;
use App\Modules\Warehouse\Services\QuarantineService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** WMS-1: stock query (client / job / mark / location / condition), per-unit ledger, reservations. */
class StockController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'], 'warehouse_id' => ['nullable', 'integer'], 'job_no' => ['nullable', 'string', 'max:30'],
            'consignment_mark' => ['nullable', 'string', 'max:60'], 'location' => ['nullable', 'string', 'max:40'],
            'condition' => ['nullable', Rule::in(Enums::CONDITIONS)], 'available_only' => ['nullable', 'boolean'],
        ]);

        $units = StockUnit::query()->with(['asnLine.asn.job', 'asnLine.asn.client', 'location'])
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['warehouse_id'] ?? WarehouseContext::currentId(), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['condition'] ?? null, fn ($q, $v) => $q->where('condition', $v))
            ->when($filters['job_no'] ?? null, fn ($q, $v) => $q->whereHas('asnLine.asn.job', fn ($j) => $j->where('job_no', 'like', "%{$v}%")))
            ->when($filters['consignment_mark'] ?? null, fn ($q, $v) => $q->whereHas('asnLine', fn ($l) => $l->where('consignment_mark', 'like', "%{$v}%")))
            ->when($filters['location'] ?? null, fn ($q, $v) => $q->whereHas('location', fn ($l) => $l->where('full_code', 'like', "%{$v}%")))
            ->when(! empty($filters['available_only']), fn ($q) => $q->where('putaway_completed', true)->where('condition', 'good')->whereColumn('qty_on_hand', '>', 'qty_reserved'))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('warehouse::stock.index', [
            'units' => $units, 'filters' => $filters,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->orderBy('code')->get(['id', 'code', 'name']),
            'conditions' => Enums::CONDITIONS,
        ]);
    }

    public function show(StockUnit $unit): View
    {
        return view('warehouse::stock.show', [
            'unit' => $unit->load(['asnLine.asn.job', 'asnLine.asn.client', 'location', 'warehouse']),
            'ledger' => $unit->ledger()->orderBy('id')->get(),
            'reservations' => $unit->reservations()->orderByDesc('id')->get(),
        ]);
    }

    public function move(Request $request, StockUnit $unit, MoveService $moves): RedirectResponse
    {
        $data = $request->validate(['location_code' => ['required', 'string', 'max:40'], 'reason' => ['nullable', 'string', 'max:255']]);
        $to = Location::query()->where('full_code', strtoupper(trim($data['location_code'])))->first();
        if ($to === null) {
            return back()->withErrors(['location_code' => __('warehouse.putaway.unknown_location', ['code' => $data['location_code']])])->withInput();
        }

        try {
            $moves->move($unit, $to, $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['location_code' => $e->getMessage()])->withInput();
        }

        return back()->with('status', __('warehouse.moves.done', ['label' => $unit->label_code, 'location' => $to->full_code]));
    }

    public function quarantine(Request $request, StockUnit $unit, QuarantineService $quarantine): RedirectResponse
    {
        $data = $request->validate([
            'condition' => ['required', Rule::in(['damaged', 'quarantine'])],
            'reason' => ['required', 'string', 'max:255'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['image', 'max:8192'],
        ]);

        $photos = [];
        foreach ($request->file('photos', []) as $file) {
            $photos[] = ['path' => $file->store('photos/stock-units', 'local'), 'original_name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'size_bytes' => $file->getSize()];
        }

        try {
            $quarantine->quarantine($unit, $data['condition'], $data['reason'], $photos);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()])->withInput();
        }

        return back()->with('status', __('warehouse.moves.quarantined', ['label' => $unit->label_code]));
    }

    public function restore(Request $request, StockUnit $unit, QuarantineService $quarantine): RedirectResponse
    {
        $data = $request->validate(['location_code' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:255']]);
        $to = Location::query()->where('full_code', strtoupper(trim($data['location_code'])))->first();
        if ($to === null) {
            return back()->withErrors(['location_code' => __('warehouse.putaway.unknown_location', ['code' => $data['location_code']])])->withInput();
        }

        try {
            $quarantine->restore($unit, $to, $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['location_code' => $e->getMessage()])->withInput();
        }

        return back()->with('status', __('warehouse.moves.restored', ['label' => $unit->label_code]));
    }

    public function reservations(): View
    {
        return view('warehouse::reservations.index', [
            'reservations' => StockReservation::query()->with(['stockUnit.asnLine.asn.client', 'stockUnit.location'])->where('status', 'active')->orderByDesc('id')->paginate(50),
        ]);
    }
}
