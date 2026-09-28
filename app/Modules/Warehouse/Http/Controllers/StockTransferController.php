<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockTransfer;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\StockTransferService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** CHANGE_REQUESTS #167 跨仓调拨: the transfer list + create form, the transfer page with its dispatch / receive / cancel actions. */
class StockTransferController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(StockTransfer::STATUSES)]]);
        $transfers = StockTransfer::query()->with(['client', 'fromWarehouse', 'toWarehouse'])->withCount('lines')->withSum('lines', 'qty')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')->paginate(50)->withQueryString();

        return view('warehouse::transfers.index', [
            'transfers' => $transfers,
            'filters' => $filters,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'currentWarehouseId' => WarehouseContext::currentId(),
            'statuses' => StockTransfer::STATUSES,
            'chargeTos' => StockTransfer::CHARGE_TO,
        ]);
    }

    public function store(Request $request, StockTransferService $service): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'from_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'to_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id'), 'different:from_warehouse_id'],
            'charge_to' => ['required', Rule::in(StockTransfer::CHARGE_TO)],
            'codes' => ['required', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], ['to_warehouse_id.different' => __('warehouse.transfers.errors.same_warehouse')]);
        $codes = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $data['codes']) ?: [])));
        try {
            $transfer = $service->create(['codes' => $codes] + $data, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['codes' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.transfers.show', $transfer)->with('status', __('warehouse.transfers.created', ['no' => $transfer->transfer_no, 'lines' => $transfer->lines()->count()]));
    }

    public function show(StockTransfer $transfer): View
    {
        $transfer->load(['client', 'fromWarehouse', 'toWarehouse', 'lines.stockUnit.asnLine', 'lines.pallet', 'lines.fromLocation']);

        return view('warehouse::transfers.show', [
            'transfer' => $transfer,
            'receivingLocations' => Location::query()->where('warehouse_id', $transfer->to_warehouse_id)->where('type', 'receiving')->where('active', true)->orderBy('full_code')->get(),
        ]);
    }

    public function dispatch(Request $request, StockTransfer $transfer, StockTransferService $service): RedirectResponse
    {
        $data = $request->validate(['vehicle' => ['nullable', 'string', 'max:100'], 'driver_name' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:255']]);
        try {
            $service->dispatch($transfer, $data, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['dispatch' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.transfers.dispatched', ['no' => $transfer->transfer_no]));
    }

    public function receive(Request $request, StockTransfer $transfer, StockTransferService $service): RedirectResponse
    {
        $data = $request->validate(['receiving_location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('warehouse_id', $transfer->to_warehouse_id)->where('type', 'receiving')]]);
        try {
            $service->receive($transfer, Location::query()->findOrFail($data['receiving_location_id']), (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['receive' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.transfers.received', ['no' => $transfer->transfer_no]));
    }

    public function cancel(StockTransfer $transfer, StockTransferService $service): RedirectResponse
    {
        try {
            $service->cancel($transfer);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['dispatch' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.transfers.cancelled', ['no' => $transfer->transfer_no]));
    }
}
