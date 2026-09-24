<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\OutboundDispatch;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\Stocktake;
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

        if ($short > 0 && $data['short_reason'] === 'not_found') {
            // CR #142: the flash names the 差异盘点 the unit was just added to (the open one of this warehouse — confirmPick created or reused it).
            $stocktake = Stocktake::query()->where('warehouse_id', $line->task->warehouse_id)->where('kind', Stocktake::KIND_DISCREPANCY)->where('status', 'counting')->latest('id')->first();

            return back()->with('status', __('warehouse.outbound.pick_short_frozen_recorded', ['short' => $short, 'stocktake' => $stocktake?->stocktake_no ?? '—']));
        }

        return back()->with('status', $short > 0
            ? __('warehouse.outbound.pick_short_recorded', ['short' => $short, 'reason' => __('warehouse.outbound.short_reasons.'.$data['short_reason'])])
            : __('warehouse.outbound.pick_confirmed'));
    }

    /** 关闭任务 (admin | warehouse_supervisor, route middleware): closes the started pick task of a cancelled order — OutboundService::closeCancelledTask. */
    /**
     * CHANGE_REQUESTS #152 全部确认拣货: the ticked, still-open lines of this wave are confirmed at their 应拣 quantity in one post — one
     * confirmPick() each, the same rules, stock moves and events as a row confirm. A short pick keeps its own row (实拣 + reason); a
     * line of a cancelled order or a cancelled task is never touched; a line the service refuses is named and the others still go.
     */
    public function pickAll(Request $request, Wave $wave, OutboundService $outbound): RedirectResponse
    {
        $data = $request->validate(['line_ids' => ['required', 'array', 'min:1'], 'line_ids.*' => ['integer']],
            ['line_ids.required' => __('warehouse.outbound.pick_all.none'), 'line_ids.min' => __('warehouse.outbound.pick_all.none')]);

        $tasks = $wave->tasks()->where('status', '!=', 'cancelled')->get(['id', 'order_id']);
        $cancelled = OutboundService::cancelledOrderIds($tasks->pluck('order_id'));
        $taskIds = $tasks->reject(fn ($task) => in_array($task->order_id, $cancelled, true))->pluck('id');
        $lines = WarehouseTaskLine::query()->with('stockUnit')->whereKey(array_map('intval', $data['line_ids']))->whereIn('task_id', $taskIds)->whereNull('confirmed_at')->orderBy('id')->get();
        if ($lines->isEmpty()) {
            return back()->withErrors(['line_ids' => __('warehouse.outbound.pick_all.none')]);
        }

        $done = 0;
        $skipped = [];
        foreach ($lines as $line) {
            try {
                $outbound->confirmPick($line, (int) $line->required_qty, auth()->id());
                $done++;
            } catch (InvalidArgumentException $e) {
                $skipped[] = ($line->stockUnit?->label_code ?? '#'.$line->id).'（'.RuleViolation::display($e).'）';
            }
        }

        $redirect = back();
        if ($done > 0) {
            $redirect->with('status', __('warehouse.outbound.pick_all.done', ['count' => $done]));
        }
        if ($skipped !== []) {
            $redirect->withErrors(['line_ids' => __('warehouse.outbound.pick_all.skipped', ['count' => count($skipped), 'list' => implode('；', $skipped)])]);
        }

        return $redirect;
    }

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

    /**
     * CHANGE_REQUESTS #155 批量打包: the ticked picked batches are packed one by one from their picked lines (OutboundService::autoPackages —
     * whole pallet = one 托盘 package, cartons × the goods line's per-carton weight and dims), then the ordinary pack() with its events and
     * the pack task. A batch that is not fully picked, already packed, cancelled, or whose lines lack a weight or dims is named and left
     * for the 打包 form; the others are packed.
     */
    public function packBulk(Request $request, OutboundService $outbound): RedirectResponse
    {
        $data = $request->validate(['fulfilment_ids' => ['required', 'array', 'min:1'], 'fulfilment_ids.*' => ['integer']],
            ['fulfilment_ids.required' => __('warehouse.outbound.pack_bulk.none'), 'fulfilment_ids.min' => __('warehouse.outbound.pack_bulk.none')]);

        $done = 0;
        $pieces = 0;
        $skipped = [];
        foreach (array_values(array_unique(array_map('intval', $data['fulfilment_ids']))) as $fulfilmentId) {
            $task = WarehouseTask::query()->where('task_type', 'pick')->where('fulfilment_id', $fulfilmentId)->with('lines.stockUnit.asnLine')->first();
            if ($task === null || $task->status !== 'done') {
                $skipped[] = '#'.$fulfilmentId.'（'.__('warehouse.outbound.errors.pack_after_pick').'）';

                continue;
            }
            $auto = $outbound->autoPackages($task);
            if ($auto['missing'] !== []) {
                $skipped[] = '#'.$fulfilmentId.'（'.__('warehouse.outbound.pack_bulk.missing', ['units' => implode('、', $auto['missing'])]).'）';

                continue;
            }
            try {
                $result = $outbound->pack($fulfilmentId, $auto['packages'], auth()->id());
                $done++;
                $pieces += count($result['packages']);
            } catch (InvalidArgumentException $e) {
                $skipped[] = '#'.$fulfilmentId.'（'.RuleViolation::display($e).'）';
            }
        }

        $redirect = redirect()->route('warehouse.outbound.index');
        if ($done > 0) {
            $redirect->with('status', __('warehouse.outbound.pack_bulk.done', ['count' => $done, 'pieces' => $pieces]));
        }
        if ($skipped !== []) {
            $redirect->withErrors(['pack' => __('warehouse.outbound.pack_bulk.skipped', ['count' => count($skipped), 'list' => implode('；', $skipped)])]);
        }

        return $redirect;
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

    /**
     * CHANGE_REQUESTS #156 批量发运交接: the ticked packed batches are handed over one by one exactly as the row form would with its
     * defaults — pallet count = the batch's 托盘 packages, the booked shipment linked automatically and its handover party used. A batch
     * without a booked shipment is skipped and named unless `unbooked` says 客户自提 / 自有司机; a batch the service refuses (financial
     * hold, cancelled order, already dispatched) is named; the rest go out with the same outbound.dispatched events.
     */
    public function dispatchBulk(Request $request, OutboundService $outbound): RedirectResponse
    {
        $data = $request->validate(['fulfilment_ids' => ['required', 'array', 'min:1'], 'fulfilment_ids.*' => ['integer'], 'unbooked' => ['nullable', Rule::in(['skip', 'client', 'driver'])]],
            ['fulfilment_ids.required' => __('warehouse.outbound.dispatch_bulk.none'), 'fulfilment_ids.min' => __('warehouse.outbound.dispatch_bulk.none')]);
        $ids = collect($data['fulfilment_ids'])->map(fn ($v) => (int) $v)->unique()->values();
        $shipments = $this->shipmentsFor($ids);
        $unbooked = $data['unbooked'] ?? 'skip';

        $done = 0;
        $packagesTotal = 0;
        $skipped = [];
        foreach ($ids as $fulfilmentId) {
            $shipment = $shipments->get($fulfilmentId);
            $booked = $shipment !== null && $shipment->status === 'booked';
            $handedTo = $booked ? (string) $shipment->handed_to : ($unbooked === 'skip' ? null : $unbooked);
            if ($handedTo === null) {
                $skipped[] = '#'.$fulfilmentId.'（'.__('warehouse.outbound.dispatch_bulk.unbooked_skipped').'）';

                continue;
            }
            $palletCount = Package::query()->where('fulfilment_id', $fulfilmentId)->where('package_type', 'pallet')->count();
            try {
                $dispatch = $outbound->dispatch($fulfilmentId, $palletCount, $handedTo, $booked ? (int) $shipment->id : null, auth()->id());
                $done++;
                $packagesTotal += (int) $dispatch->package_count;
            } catch (InvalidArgumentException $e) {
                $skipped[] = '#'.$fulfilmentId.'（'.RuleViolation::display($e).'）';
            }
        }

        $redirect = redirect()->route('warehouse.outbound.index');
        if ($done > 0) {
            $redirect->with('status', __('warehouse.outbound.dispatch_bulk.done', ['count' => $done, 'packages' => $packagesTotal]));
        }
        if ($skipped !== []) {
            $redirect->withErrors(['dispatch' => __('warehouse.outbound.dispatch_bulk.skipped', ['count' => count($skipped), 'list' => implode('；', $skipped)])]);
        }

        return $redirect;
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
