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
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\StockService;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** B4 pages: one outbound board (release wave → pick → pack → dispatch) plus a wave sheet and a packing form. */
class OutboundController extends Controller
{
    public function index(ExceptionService $exceptions, StockService $stock): View
    {
        $warehouseId = WarehouseContext::currentId();
        $pickTasks = WarehouseTask::query()->where('task_type', 'pick')->whereNotNull('fulfilment_id');

        $ready = DB::table('fulfilments')->join('orders', 'orders.id', '=', 'fulfilments.order_id')->join('clients', 'clients.id', '=', 'orders.client_id')
            ->where('fulfilments.status', 'allocated')
            ->where('orders.operational_status', '!=', 'cancelled') // audit 2026-09-22 OUTBOUND-02: a cancelled order's batch stays `allocated` in OMS — never offer it for release
            ->whereNotIn('fulfilments.id', (clone $pickTasks)->select('fulfilment_id'))
            ->when($warehouseId, fn ($q, $v) => $q->where('fulfilments.warehouse_id', $v))
            ->orderBy('orders.requested_date')->orderBy('fulfilments.id')
            ->get(['fulfilments.id as fulfilment_id', 'fulfilments.warehouse_id', 'orders.id as order_id', 'orders.order_no', 'orders.requested_date', 'orders.deliver_to_suburb', 'orders.client_id', 'clients.name as client_name']);

        // Audit 2026-09-22 OUTBOUND-12 (CR #141): size per row (箱数 = Σ fulfilment_lines.qty) so the supervisor sees what a wave will hold.
        $cartons = DB::table('fulfilment_lines')->whereIn('fulfilment_id', $ready->pluck('fulfilment_id'))->groupBy('fulfilment_id')->selectRaw('fulfilment_id, sum(qty) as cartons')->pluck('cartons', 'fulfilment_id');
        $ready->each(fn ($r) => $r->cartons = (int) ($cartons[$r->fulfilment_id] ?? 0));

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
            // Audit 2026-09-10: a financial hold lets the batch be picked and packed but refuses the handover — say so on the board instead of after the click.
            'heldFulfilments' => $toDispatch->filter(fn ($packages) => $exceptions->hasActiveHold('financial', $packages->first()->client_id, $packages->first()->order_id))->keys()->all(),
            // OUTBOUND-02: a cancelled order's rows show a badge instead of the 打包 / 发运交接 controls (the service refuses them anyway).
            'cancelledOrders' => OutboundService::cancelledOrderIds($orderNos->keys()),
            'shortages' => $this->shortages($stock),
            'shipments' => $this->shipmentsFor($toDispatch->keys()),
            'today' => today()->toDateString(),
        ]);
    }

    /**
     * Audit 2026-09-22 OUTBOUND-03 (CR #141): the booked shipment behind each 待发运 batch, read-only from Transport's tables (shipments,
     * carriers, transport_quotes — the same DB::table pattern this board already uses for orders / fulfilments; Transport exposes no
     * shipment read in contracts/services.md). Keyed by fulfilment_id; `handed_to` is the default the quote source implies.
     *
     * @return Collection<int, object{id:int, shipment_no:string, status:string, carrier:?string, source:?string, handed_to:string}>
     */
    private function shipmentsFor(Collection $fulfilmentIds): Collection
    {
        if ($fulfilmentIds->isEmpty()) {
            return collect();
        }

        return DB::table('shipments')->leftJoin('carriers', 'carriers.id', '=', 'shipments.carrier_id')->leftJoin('transport_quotes', 'transport_quotes.id', '=', 'shipments.selected_quote_id')
            ->whereIn('shipments.fulfilment_id', $fulfilmentIds)->whereNotIn('shipments.status', ['booking_cancelled'])
            ->orderByDesc('shipments.id')
            ->get(['shipments.id', 'shipments.fulfilment_id', 'shipments.shipment_no', 'shipments.status', 'carriers.name as carrier', 'transport_quotes.source'])
            ->unique('fulfilment_id')->keyBy('fulfilment_id')
            ->map(fn ($s) => (object) ['id' => (int) $s->id, 'shipment_no' => $s->shipment_no, 'status' => $s->status, 'carrier' => $s->carrier, 'source' => $s->source, 'handed_to' => $s->source === 'own_fleet' ? 'driver' : 'carrier']);
    }

    /**
     * Item 4C (2026-09-10): confirmed orders that could not be (fully) reserved — they never reach 待释放, so the board says why.
     *
     * @return Collection<int, object{order_id:int, order_no:string, client_name:string, requested_date:?string, status:string, lines:Collection}>
     */
    private function shortages(StockService $stock): Collection
    {
        $lines = DB::table('order_lines')->join('orders', 'orders.id', '=', 'order_lines.order_id')->join('clients', 'clients.id', '=', 'orders.client_id')
            ->leftJoin('asn_lines', 'asn_lines.id', '=', 'order_lines.asn_line_id')->leftJoin('asns', 'asns.id', '=', 'asn_lines.asn_id')
            ->where('order_lines.qty_backordered', '>', 0)->where('orders.order_type', 'from_stock')
            ->whereNotIn('orders.operational_status', ['dispatched', 'delivered', 'returned', 'cancelled', 'closed'])
            ->orderBy('orders.requested_date')->orderBy('orders.id')->limit(300)
            ->get(['orders.id as order_id', 'orders.order_no', 'orders.client_id', 'orders.requested_date', 'orders.operational_status', 'clients.name as client_name',
                'order_lines.id as line_id', 'order_lines.description_cn', 'order_lines.description_en', 'order_lines.carton_qty', 'order_lines.qty_backordered', 'order_lines.asn_line_id', 'asns.asn_no', 'asns.id as asn_id']);

        return $lines->groupBy('order_id')->map(function ($rows) use ($stock) {
            $first = $rows->first();

            return (object) [
                'order_id' => (int) $first->order_id, 'order_no' => $first->order_no, 'client_name' => $first->client_name, 'requested_date' => $first->requested_date, 'status' => $first->operational_status,
                'lines' => $rows->map(fn ($r) => (object) [
                    'line_id' => (int) $r->line_id, 'description' => $r->description_cn ?: $r->description_en, 'need' => (int) $r->carton_qty, 'short' => (int) $r->qty_backordered,
                    'available' => $r->asn_line_id ? $stock->onHand((int) $first->client_id, (int) $r->asn_line_id)['qty_available'] : 0, 'asn_no' => $r->asn_no, 'asn_id' => $r->asn_id,
                ])->values(),
            ];
        })->values();
    }

    public function release(Request $request, OutboundService $outbound): RedirectResponse
    {
        // Audit 2026-09-22 OUTBOUND-12 (CR #141): un-ticking every row used to release EVERY allocated batch of the warehouse — at least one order is required.
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'client_id' => ['nullable', 'integer'],
            'requested_date' => ['nullable', 'date'],
            'order_ids' => ['required', 'array', 'min:1'], 'order_ids.*' => ['integer'],
        ], ['order_ids.required' => __('warehouse.outbound.errors.select_orders'), 'order_ids.min' => __('warehouse.outbound.errors.select_orders')]);

        try {
            $result = $outbound->releaseWave((int) $data['warehouse_id'], ['client_id' => $data['client_id'] ?? null, 'requested_date' => $data['requested_date'] ?? null, 'order_ids' => $data['order_ids'] ?? []], auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['warehouse_id' => RuleViolation::display($e)]);
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
            // Audit 2026-09-22 OUTBOUND-02: a cancelled order's card tells the picker to put the goods back and offers 关闭任务 to the supervisor.
            'cancelledOrders' => OutboundService::cancelledOrderIds($wave->tasks->pluck('order_id')),
            // Tester feedback #9: 打包 only while the fulfilment is not packed yet; afterwards the card says so and points to 发运交接.
            'packedFulfilments' => Package::query()->whereIn('fulfilment_id', $fulfilmentIds)->distinct()->pluck('fulfilment_id')->all(),
            'dispatchedFulfilments' => OutboundDispatch::query()->whereIn('fulfilment_id', $fulfilmentIds)->distinct()->pluck('fulfilment_id')->all(),
        ]);
    }

    public function pick(Request $request, WarehouseTaskLine $line, OutboundService $outbound): RedirectResponse
    {
        // Audit 2026-09-22 OUTBOUND-08 (CR #141): a short pick is confirmed with a reason (+ note) and gets its own flash; the wave page shows a badge.
        $validator = Validator::make($request->all(), [
            'picked_qty' => ['required', 'integer', 'min:0'],
            'short_reason' => ['nullable', Rule::in(OutboundService::SHORT_REASONS)],
            'short_note' => ['nullable', 'string', 'max:255'],
        ]);
        $validator->after(function ($v) use ($request, $line) {
            if ($request->filled('picked_qty') && (int) $request->input('picked_qty') < $line->required_qty && ! $request->filled('short_reason')) {
                $v->errors()->add('short_reason', __('warehouse.outbound.errors.short_reason_required', ['required' => $line->required_qty, 'picked' => (int) $request->input('picked_qty')]));
            }
        });
        $data = $validator->validate();
        $short = $line->required_qty - (int) $data['picked_qty'];

        try {
            $outbound->confirmPick($line, (int) $data['picked_qty'], auth()->id(), $short > 0 ? $data['short_reason'] : null, $data['short_note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['picked_qty' => RuleViolation::display($e)]);
        }

        return back()->with('status', $short > 0
            ? __('warehouse.outbound.pick_short_recorded', ['short' => $short, 'reason' => __('warehouse.outbound.short_reasons.'.$data['short_reason'])])
            : __('warehouse.outbound.pick_confirmed'));
    }

    /** 关闭任务 (admin | warehouse_supervisor, route middleware): closes the started pick task of a cancelled order — OutboundService::closeCancelledTask. */
    public function closeTask(WarehouseTask $task, OutboundService $outbound): RedirectResponse
    {
        try {
            $outbound->closeCancelledTask($task, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['close' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.outbound.task_closed', ['task_no' => $task->task_no]));
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
        $validator = Validator::make($request->all(), [
            'packages' => ['required', 'array'],
            'packages.*.package_type' => ['nullable', Rule::in(Enums::PACKAGE_TYPES)],
            'packages.*.qty' => ['nullable', 'integer', 'min:1', 'max:'.OutboundService::MAX_PACKAGES], // 件数: identical packages on one row (audit 2026-09-22 OUTBOUND-01)
            'packages.*.weight_kg' => ['nullable', 'numeric', 'min:0.001'],
            'packages.*.length_mm' => ['nullable', 'integer', 'min:1'], 'packages.*.width_mm' => ['nullable', 'integer', 'min:1'], 'packages.*.height_mm' => ['nullable', 'integer', 'min:1'],
        ]);
        // Audit 2026-09-22 OUTBOUND-09 (CR #141): a row with a weight is a package — it needs its type and all three dims (Transport quoted 0 mm otherwise, a
        // typeless row was dropped silently). Rows with nothing typed stay ignorable spare rows. Errors name the visible row number.
        $validator->after(function ($v) use ($request) {
            foreach (array_values(array_filter((array) $request->input('packages', []), 'is_array')) as $i => $row) {
                if (! filled($row['weight_kg'] ?? null)) {
                    continue;
                }
                if (! filled($row['package_type'] ?? null)) {
                    $v->errors()->add("packages.{$i}.package_type", __('warehouse.outbound.errors.row_type_required', ['row' => $i + 1]));
                }
                if (! filled($row['length_mm'] ?? null) || ! filled($row['width_mm'] ?? null) || ! filled($row['height_mm'] ?? null)) {
                    $v->errors()->add("packages.{$i}.length_mm", __('warehouse.outbound.errors.row_dims_required', ['row' => $i + 1]));
                }
            }
        });
        $data = $validator->validate();
        $packages = collect($data['packages'])->filter(fn ($p) => filled($p['weight_kg'] ?? null) && filled($p['package_type'] ?? null))
            ->map(fn ($p) => ['package_type' => $p['package_type'], 'qty' => (int) ($p['qty'] ?? 1), 'weight_kg' => (float) $p['weight_kg'], 'length_mm' => $p['length_mm'] ?? null, 'width_mm' => $p['width_mm'] ?? null, 'height_mm' => $p['height_mm'] ?? null])->values()->all();

        try {
            $result = $outbound->pack($fulfilment, $packages, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['packages' => RuleViolation::display($e)])->withInput();
        }

        return redirect()->route('warehouse.outbound.index')->with('status', __('warehouse.outbound.packed', ['count' => $result['packages']->count()]));
    }

    public function dispatch(Request $request, int $fulfilment, OutboundService $outbound): RedirectResponse
    {
        $data = $request->validate(['pallet_count' => ['required', 'integer', 'min:0'], 'handed_to' => ['required', Rule::in(Enums::HANDED_TO)], 'shipment_id' => ['nullable', 'integer']]);
        // Audit 2026-09-22 OUTBOUND-03 (CR #141): a typed / prefilled shipment id must be this batch's shipment — a wrong one used to fail later inside Transport's consumer.
        if (! empty($data['shipment_id']) && ! DB::table('shipments')->where('id', $data['shipment_id'])->where('fulfilment_id', $fulfilment)->exists()) {
            return back()->withErrors(['pallet_count' => __('warehouse.outbound.errors.shipment_not_of_fulfilment', ['id' => $data['shipment_id']])]);
        }

        try {
            $dispatch = $outbound->dispatch($fulfilment, (int) $data['pallet_count'], $data['handed_to'], $data['shipment_id'] ?? null, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['pallet_count' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.outbound.dispatched', ['packages' => $dispatch->package_count, 'pallets' => $dispatch->pallet_count]));
    }
}
