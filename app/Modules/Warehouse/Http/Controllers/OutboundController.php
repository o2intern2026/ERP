<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\OutboundDispatch;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\WarehouseTaskLine;
use App\Modules\Warehouse\Models\Wave;
use App\Modules\Warehouse\Services\OutboundService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** B4 pages: one outbound board (release wave → pick → pack → dispatch) plus a wave sheet and a packing form. */
class OutboundController extends Controller
{
    public function index(): View
    {
        $warehouseId = WarehouseContext::currentId();
        $pickTasks = WarehouseTask::query()->where('task_type', 'pick')->whereNotNull('fulfilment_id');

        $ready = DB::table('fulfilments')->join('orders', 'orders.id', '=', 'fulfilments.order_id')->join('clients', 'clients.id', '=', 'orders.client_id')
            ->where('fulfilments.status', 'allocated')
            ->whereNotIn('fulfilments.id', (clone $pickTasks)->select('fulfilment_id'))
            ->when($warehouseId, fn ($q, $v) => $q->where('fulfilments.warehouse_id', $v))
            ->orderBy('orders.requested_date')->orderBy('fulfilments.id')
            ->get(['fulfilments.id as fulfilment_id', 'fulfilments.warehouse_id', 'orders.id as order_id', 'orders.order_no', 'orders.requested_date', 'orders.deliver_to_suburb', 'orders.client_id', 'clients.name as client_name']);

        $picking = (clone $pickTasks)->with(['wave', 'lines'])->whereIn('status', ['pending', 'in_progress'])->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v))->orderBy('id')->get();
        $toPack = (clone $pickTasks)->with('lines')->where('status', 'done')->whereNotIn('fulfilment_id', Package::query()->select('fulfilment_id'))->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v))->orderBy('id')->get();
        $toDispatch = Package::query()->whereNotIn('fulfilment_id', OutboundDispatch::query()->select('fulfilment_id'))->orderBy('id')->get()->groupBy('fulfilment_id');
        $orderNos = DB::table('orders')->whereIn('id', $picking->pluck('order_id')->merge($toPack->pluck('order_id'))->merge($toDispatch->flatten()->pluck('order_id'))->unique())->pluck('order_no', 'id');

        return view('warehouse::outbound.index', [
            'ready' => $ready, 'picking' => $picking, 'toPack' => $toPack, 'toDispatch' => $toDispatch, 'orderNos' => $orderNos,
            'waves' => Wave::query()->with('warehouse')->withCount('tasks')->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v))->orderByDesc('id')->limit(30)->get(),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(),
            'currentWarehouseId' => $warehouseId,
            'handedTo' => Enums::HANDED_TO,
        ]);
    }

    public function release(Request $request, OutboundService $outbound): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'client_id' => ['nullable', 'integer'],
            'requested_date' => ['nullable', 'date'],
            'order_ids' => ['nullable', 'array'], 'order_ids.*' => ['integer'],
        ]);

        try {
            $result = $outbound->releaseWave((int) $data['warehouse_id'], ['client_id' => $data['client_id'] ?? null, 'requested_date' => $data['requested_date'] ?? null, 'order_ids' => $data['order_ids'] ?? []], auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['warehouse_id' => $e->getMessage()]);
        }

        return redirect()->route('warehouse.outbound.waves.show', $result['wave'])->with('status', __('warehouse.outbound.released', ['wave_no' => $result['wave']->wave_no, 'count' => $result['tasks']->count()]));
    }

    public function wave(Wave $wave): View
    {
        $wave->load(['warehouse', 'tasks.lines.stockUnit', 'tasks.lines.location']);

        $fulfilmentIds = $wave->tasks->pluck('fulfilment_id')->filter()->unique()->values();

        return view('warehouse::outbound.wave', [
            'wave' => $wave,
            'orderNos' => DB::table('orders')->whereIn('id', $wave->tasks->pluck('order_id'))->pluck('order_no', 'id'),
            // Tester feedback #9: 打包 only while the fulfilment is not packed yet; afterwards the card says so and points to 发运交接.
            'packedFulfilments' => Package::query()->whereIn('fulfilment_id', $fulfilmentIds)->distinct()->pluck('fulfilment_id')->all(),
            'dispatchedFulfilments' => OutboundDispatch::query()->whereIn('fulfilment_id', $fulfilmentIds)->distinct()->pluck('fulfilment_id')->all(),
        ]);
    }

    public function pick(Request $request, WarehouseTaskLine $line, OutboundService $outbound): RedirectResponse
    {
        $data = $request->validate(['picked_qty' => ['required', 'integer', 'min:0']]);

        try {
            $outbound->confirmPick($line, (int) $data['picked_qty'], auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['picked_qty' => $e->getMessage()]);
        }

        return back()->with('status', __('warehouse.outbound.pick_confirmed'));
    }

    public function packForm(int $fulfilment): View|RedirectResponse
    {
        $task = WarehouseTask::query()->where('task_type', 'pick')->where('fulfilment_id', $fulfilment)->with('lines.stockUnit.asnLine')->firstOrFail();
        // Not a 409 page any more (tester feedback #9): say why in Chinese and go back to the board.
        if (Package::query()->where('fulfilment_id', $fulfilment)->exists()) {
            return redirect()->route('warehouse.outbound.index')->withErrors(['pack' => __('warehouse.outbound.already_packed', ['id' => $fulfilment])]);
        }
        if ($task->status !== 'done') {
            return redirect()->route('warehouse.outbound.waves.show', $task->wave_id)->withErrors(['pack' => __('warehouse.outbound.pack_after_pick', ['id' => $fulfilment])]);
        }

        return view('warehouse::outbound.pack', ['task' => $task, 'order' => DB::table('orders')->where('id', $task->order_id)->first(), 'packageTypes' => Enums::PACKAGE_TYPES]);
    }

    public function pack(Request $request, int $fulfilment, OutboundService $outbound): RedirectResponse
    {
        $data = $request->validate([
            'packages' => ['required', 'array'],
            'packages.*.package_type' => ['nullable', Rule::in(Enums::PACKAGE_TYPES)],
            'packages.*.weight_kg' => ['nullable', 'numeric', 'min:0.001'],
            'packages.*.length_mm' => ['nullable', 'integer', 'min:1'], 'packages.*.width_mm' => ['nullable', 'integer', 'min:1'], 'packages.*.height_mm' => ['nullable', 'integer', 'min:1'],
        ]);
        $packages = collect($data['packages'])->filter(fn ($p) => filled($p['weight_kg'] ?? null) && filled($p['package_type'] ?? null))
            ->map(fn ($p) => ['package_type' => $p['package_type'], 'weight_kg' => (float) $p['weight_kg'], 'length_mm' => $p['length_mm'] ?? null, 'width_mm' => $p['width_mm'] ?? null, 'height_mm' => $p['height_mm'] ?? null])->values()->all();

        try {
            $result = $outbound->pack($fulfilment, $packages, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['packages' => $e->getMessage()])->withInput();
        }

        return redirect()->route('warehouse.outbound.index')->with('status', __('warehouse.outbound.packed', ['count' => $result['packages']->count()]));
    }

    public function dispatch(Request $request, int $fulfilment, OutboundService $outbound): RedirectResponse
    {
        $data = $request->validate(['pallet_count' => ['required', 'integer', 'min:0'], 'handed_to' => ['required', Rule::in(Enums::HANDED_TO)], 'shipment_id' => ['nullable', 'integer']]);

        try {
            $dispatch = $outbound->dispatch($fulfilment, (int) $data['pallet_count'], $data['handed_to'], $data['shipment_id'] ?? null, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['pallet_count' => $e->getMessage()]);
        }

        return back()->with('status', __('warehouse.outbound.dispatched', ['packages' => $dispatch->package_count, 'pallets' => $dispatch->pallet_count]));
    }
}
