<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\PalletService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** CHANGE_REQUESTS #169 托盘管理: the pallet list (in use / empty / free), the pallet page with release, repalletise and corrections. */
class PalletController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(Enums::PALLET_STATUSES)],
            'free' => ['nullable', 'boolean'], 'q' => ['nullable', 'string', 'max:40'],
        ]);
        $warehouseId = $filters['warehouse_id'] ?? WarehouseContext::currentId();
        $pallets = Pallet::query()->with(['client', 'location', 'warehouse'])->withCount('units')->withSum('units', 'qty_on_hand')
            ->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(! empty($filters['free']), fn ($q) => $q->where('status', 'empty')->whereNull('location_id'))
            ->when(filled($filters['q'] ?? null), fn ($q) => $q->where(fn ($w) => $w->where('pallet_no', 'like', '%'.strtoupper(trim((string) $filters['q'])).'%')->orWhereHas('client', fn ($c) => $c->where('name', 'like', '%'.trim((string) $filters['q']).'%'))))
            ->orderByDesc('id')->paginate(100)->withQueryString();

        return view('warehouse::pallets.index', [
            'pallets' => $pallets,
            'filters' => ['warehouse_id' => $warehouseId] + $filters,
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code']),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => Enums::PALLET_STATUSES,
            'freeCount' => Pallet::query()->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v))->where('status', 'empty')->whereNull('location_id')->count(),
        ]);
    }

    public function show(Pallet $pallet): View
    {
        return view('warehouse::pallets.show', [
            'pallet' => $pallet->load(['client', 'location', 'warehouse']),
            'units' => StockUnit::query()->withoutGlobalScopes()->with('asnLine.asn')->where('pallet_id', $pallet->id)->orderBy('id')->get(),
            'palletSources' => Enums::PALLET_SOURCES,
            'palletClasses' => Enums::PALLET_CLASSES,
        ]);
    }

    /** 修改托盘信息 (admin | warehouse_supervisor): source / class / dims / weight after receiving. */
    public function update(Request $request, Pallet $pallet, PalletService $service): RedirectResponse
    {
        $data = $request->validate([
            'pallet_source' => ['required', Rule::in(Enums::PALLET_SOURCES)],
            'pallet_class' => ['nullable', Rule::in(Enums::PALLET_CLASSES)],
            'pallet_class_overridden_reason' => ['nullable', 'string', 'max:255'],
            'length_mm' => ['nullable', 'integer', 'min:1'], 'width_mm' => ['nullable', 'integer', 'min:1'], 'height_mm' => ['nullable', 'integer', 'min:1'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);
        $service->update($pallet, $data);

        return back()->with('status', __('warehouse.pallets.updated', ['no' => $pallet->pallet_no]));
    }

    /** 清走空托盘: the emptied pallet leaves its slot and joins the free pool. */
    public function release(Pallet $pallet, PalletService $service): RedirectResponse
    {
        try {
            $service->release($pallet);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['release' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.pallets.released', ['no' => $pallet->pallet_no]));
    }

    /** 拆托 / 并托: a unit onto the named pallet (blank = off its pallet, loose). */
    public function repalletise(Request $request, Pallet $pallet, PalletService $service): RedirectResponse
    {
        $data = $request->validate(['unit_code' => ['required', 'string', 'max:60'], 'target' => ['nullable', 'string', 'max:30']]);
        $unit = StockUnit::query()->withoutGlobalScopes()->where('pallet_id', $pallet->id)->scanCode($data['unit_code'])->first();
        if ($unit === null) {
            return back()->withErrors(['repalletise' => __('warehouse.pallets.errors.unit_not_here', ['label' => $data['unit_code']])]);
        }
        $target = null;
        if (filled($data['target'] ?? null)) {
            $target = Pallet::query()->scanCode($data['target'])->first();
            if ($target === null) {
                return back()->withErrors(['repalletise' => __('warehouse.pallets.errors.unknown_pallet', ['no' => $data['target']])]);
            }
        }
        try {
            $service->repalletise($unit, $target, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['repalletise' => RuleViolation::display($e)]);
        }

        return back()->with('status', $target === null
            ? __('warehouse.pallets.taken_off', ['label' => $unit->label_code, 'no' => $pallet->pallet_no])
            : __('warehouse.pallets.moved_to', ['label' => $unit->label_code, 'no' => $target->pallet_no]));
    }
}
