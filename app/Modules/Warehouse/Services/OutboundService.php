<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Events\OutboundDispatched;
use App\Modules\Warehouse\Events\OutboundPacked;
use App\Modules\Warehouse\Events\StockReleased;
use App\Modules\Warehouse\Models\OutboundDispatch;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\StockLedgerEntry;
use App\Modules\Warehouse\Models\StockReservation;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\WarehouseTaskLine;
use App\Modules\Warehouse\Models\Wave;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\StockService;
use App\Support\Exceptions\RuleViolation;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * B4 outbound: wave → pick tasks (location order) → confirm picks (ledger `pick`, reservations consumed, Pick Short
 * exception) → pack (packages + outbound.packed, the billing input) → dispatch (handover, load task, outbound.dispatched).
 * Reads OMS's fulfilments / orders read-only; order status is OMS's job after our events (§4.3 rule 6).
 * A cancelled order (orders.operational_status, read-only) is out of every step: never listed for release, and pick / pack / dispatch
 * refuse it; a pick task that was already running is closed by a supervisor on the wave page (audit 2026-09-22 OUTBOUND-02).
 */
final class OutboundService
{
    /** Most Package rows one pack may create (a row's 件数 expands into that many) — audit 2026-09-22 OUTBOUND-01. */
    public const MAX_PACKAGES = 200;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly StockLedger $ledger,
        private readonly ExceptionService $exceptions,
        private readonly OutboxPublisher $outbox,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  array{client_id?:?int, requested_date?:?string, order_ids?:list<int>}  $filters
     * @return array{wave: Wave, tasks: Collection<int, WarehouseTask>}
     */
    public function releaseWave(int $warehouseId, array $filters = [], ?int $userId = null): array
    {
        return DB::transaction(function () use ($warehouseId, $filters, $userId): array {
            $fulfilments = DB::table('fulfilments')->join('orders', 'orders.id', '=', 'fulfilments.order_id')
                ->where('fulfilments.warehouse_id', $warehouseId)->where('fulfilments.status', 'allocated')
                ->where('orders.operational_status', '!=', 'cancelled') // the fulfilment row stays `allocated` after a cancel — the order is what says so (OUTBOUND-02)
                ->whereNotIn('fulfilments.id', WarehouseTask::query()->withoutGlobalScopes()->where('task_type', 'pick')->whereNotNull('fulfilment_id')->select('fulfilment_id'))
                ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('orders.client_id', $v))
                ->when($filters['requested_date'] ?? null, fn ($q, $v) => $q->whereDate('orders.requested_date', '<=', $v))
                ->when(! empty($filters['order_ids']), fn ($q) => $q->whereIn('orders.id', $filters['order_ids']))
                ->orderBy('orders.requested_date')->orderBy('fulfilments.id')
                ->get(['fulfilments.id as fulfilment_id', 'orders.id as order_id', 'orders.job_id', 'orders.client_id']);

            if ($fulfilments->isEmpty()) {
                throw new InvalidArgumentException(__('warehouse.outbound.errors.no_candidates'));
            }

            $wave = Wave::query()->create(['wave_no' => DocumentNumbers::next(Wave::query(), 'wave_no', 'WAV'), 'warehouse_id' => $warehouseId, 'status' => 'released', 'released_by' => $userId, 'released_at' => now()]);
            $tasks = collect();

            foreach ($fulfilments as $f) {
                $lineIds = DB::table('fulfilment_lines')->where('fulfilment_id', $f->fulfilment_id)->pluck('qty', 'order_line_id');
                $reservations = StockReservation::query()->where('order_id', $f->order_id)->where('status', 'active')->whereIn('order_line_id', $lineIds->keys())
                    ->with('stockUnit.location')->get()->filter(fn ($r) => $r->stockUnit->warehouse_id === $warehouseId)
                    ->sortBy(fn ($r) => $r->stockUnit->location?->full_code ?? '~');

                $task = $this->tasks->create('pick', ['job_id' => $f->job_id, 'client_id' => $f->client_id, 'warehouse_id' => $warehouseId, 'source_type' => 'fulfilment', 'source_id' => $f->fulfilment_id, 'order_id' => $f->order_id, 'fulfilment_id' => $f->fulfilment_id, 'wave_id' => $wave->id, 'priority' => 5]);
                foreach ($reservations as $r) {
                    $task->lines()->create(['stock_unit_id' => $r->stock_unit_id, 'asn_line_id' => $r->stockUnit->asn_line_id, 'order_line_id' => $r->order_line_id, 'location_id' => $r->stockUnit->location_id, 'required_qty' => $r->qty]);
                }
                $tasks->push($task->load('lines'));
            }

            return ['wave' => $wave, 'tasks' => $tasks];
        });
    }

    /** Confirm one pick line: stock leaves the unit (ledger `pick`), the reservation is consumed, a shortfall raises Pick Short. */
    public function confirmPick(WarehouseTaskLine $line, int $pickedQty, ?int $userId = null): WarehouseTaskLine
    {
        if ($pickedQty < 0 || $pickedQty > $line->required_qty) {
            throw new InvalidArgumentException(__('warehouse.outbound.errors.picked_range', ['max' => $line->required_qty]));
        }
        $task = $line->task;
        if ($task->status === 'done' || $line->confirmed_at !== null) {
            throw new InvalidArgumentException(__('warehouse.outbound.errors.pick_confirmed'));
        }
        if ($task->status === 'cancelled') {
            throw new RuleViolation("Task {$task->task_no} is cancelled.", 'warehouse.tasks.not_pending', ['task_no' => $task->task_no]);
        }
        $this->refuseCancelledOrder($task->order_id);

        return DB::transaction(function () use ($line, $task, $pickedQty, $userId): WarehouseTaskLine {
            $unit = StockUnit::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($line->stock_unit_id);
            $reservation = StockReservation::query()->where('order_id', $task->order_id)->where('order_line_id', $line->order_line_id)->where('stock_unit_id', $unit->id)->where('status', 'active')->lockForUpdate()->first();

            if ($pickedQty > 0) {
                $unit->qty_reserved = max(0, $unit->qty_reserved - $pickedQty);
                $this->ledger->record($unit, 'pick', -$pickedQty, ['from_location_id' => $unit->location_id, 'source_type' => 'task', 'source_id' => $task->id, 'operator_id' => $userId]);
            }
            if ($reservation !== null) {
                $shortfall = $reservation->qty - $pickedQty;
                if ($shortfall > 0) {
                    // What could not be picked is released so the coordinator can decide (backorder / partial / cancel).
                    $this->ledger->record($unit, 'release', -$shortfall, ['source_type' => 'task', 'source_id' => $task->id, 'operator_id' => $userId]);
                }
                $reservation->update(['status' => 'consumed', 'released_at' => $shortfall > 0 ? now() : null, 'released_reason' => $shortfall > 0 ? 'pick_short' : null]);
            }

            $line->update(['completed_qty' => $pickedQty, 'confirmed_at' => now()]);
            if ($task->status === 'pending') {
                $task->update(['status' => 'in_progress', 'started_at' => now(), 'assigned_user_id' => $task->assigned_user_id ?? $userId]);
            }

            if ($pickedQty < $line->required_qty) {
                $this->exceptions->raise('pick_short', 'warehouse', [
                    'job_id' => $task->job_id, 'client_id' => $task->client_id, 'order_id' => $task->order_id, 'source_type' => 'fulfilment', 'source_id' => $task->fulfilment_id,
                    'message' => "{$task->task_no}: {$unit->label_code} picked {$pickedQty} of {$line->required_qty}", 'created_by' => $userId,
                ]);
            }

            if ($task->lines()->whereNull('confirmed_at')->doesntExist()) {
                $this->tasks->complete($task->fresh(), [], 'fulfilment:'.$task->fulfilment_id);
                $this->closeWaveIfDone($task->wave_id);
            }

            return $line->fresh();
        });
    }

    /**
     * Pack a picked fulfilment: package rows (one label each) and the outbound.packed event Billing / TMS need.
     * A row's `qty` (件数, default 1) expands into that many identical Package rows — labels PKG-n-01, -02 … run over the expanded list,
     * so label_count and the carrier's item list count pieces, not form rows (audit 2026-09-22 OUTBOUND-01). At most MAX_PACKAGES.
     *
     * @param  list<array{package_type:string, weight_kg:float, qty?:int, length_mm?:?int, width_mm?:?int, height_mm?:?int}>  $packages
     */
    public function pack(int $fulfilmentId, array $packages, ?int $userId = null): array
    {
        $task = WarehouseTask::query()->withoutGlobalScopes()->where('task_type', 'pick')->where('fulfilment_id', $fulfilmentId)->with('lines.stockUnit.asnLine')->firstOrFail();
        if ($task->status !== 'done') {
            throw new InvalidArgumentException(__('warehouse.outbound.errors.pack_after_pick'));
        }
        if (Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentId)->exists()) {
            throw new InvalidArgumentException(__('warehouse.outbound.errors.already_packed'));
        }
        $this->refuseCancelledOrder($task->order_id);
        $packages = self::expandPackages($packages);
        if ($packages === []) {
            throw new InvalidArgumentException(__('warehouse.outbound.errors.need_package'));
        }
        if (count($packages) > self::MAX_PACKAGES) {
            throw new RuleViolation('At most '.self::MAX_PACKAGES.' packages per pack.', 'warehouse.outbound.errors.too_many_packages', ['max' => self::MAX_PACKAGES]);
        }

        return DB::transaction(function () use ($task, $fulfilmentId, $packages, $userId): array {
            $order = DB::table('orders')->where('id', $task->order_id)->first();
            $created = collect();
            foreach (array_values($packages) as $i => $p) {
                $created->push(Package::query()->create([
                    'fulfilment_id' => $fulfilmentId, 'order_id' => $task->order_id, 'job_id' => $task->job_id, 'client_id' => $task->client_id,
                    'package_type' => $p['package_type'], 'weight_kg' => $p['weight_kg'], 'length_mm' => $p['length_mm'] ?? null, 'width_mm' => $p['width_mm'] ?? null, 'height_mm' => $p['height_mm'] ?? null,
                    'carton_label' => sprintf('PKG-%d-%02d', $fulfilmentId, $i + 1),
                ]));
            }

            $lines = $task->lines->filter(fn ($l) => $l->completed_qty > 0)->map(function (WarehouseTaskLine $l) use ($task) {
                [$unitType, $unitWeight] = $this->pickBilling($l->stockUnit, $l, $task);

                return ['order_line_id' => $l->order_line_id, 'stock_unit_id' => $l->stock_unit_id, 'unit_type' => $unitType, 'qty' => $l->completed_qty, 'unit_weight_kg' => $unitWeight];
            })->values();

            $cutoff = $order ? DB::table('clients')->where('id', $order->client_id)->value('dispatch_cutoff_time') : null;
            $isUrgent = $order !== null && $cutoff !== null && (string) $order->requested_date === today()->toDateString() && now()->format('H:i:s') > $cutoff;

            $event = new OutboundPacked([
                'order_id' => $task->order_id, 'order_no' => $order?->order_no, 'fulfilment_id' => $fulfilmentId, 'job_id' => $task->job_id, 'client_id' => $task->client_id, 'warehouse_id' => $task->warehouse_id,
                'is_urgent' => $isUrgent,
                'lines' => $lines->all(),
                'packages' => $created->map(fn (Package $p) => ['package_id' => $p->id, 'package_type' => $p->package_type, 'weight_kg' => (float) $p->weight_kg, 'length_mm' => $p->length_mm, 'width_mm' => $p->width_mm, 'height_mm' => $p->height_mm, 'carton_label' => $p->carton_label])->all(),
                'pallet_count' => $lines->where('unit_type', 'pallet')->count(),
                'carton_count' => (int) $lines->where('unit_type', 'carton')->sum('qty'),
                'label_count' => $created->count(),
                'packed_by' => $userId, 'packed_at' => now()->toIso8601String(),
            ], $task->job_id, $task->client_id, 'fulfilment:'.$fulfilmentId);
            $this->outbox->publish($event);

            $packTask = $this->tasks->create('pack', ['job_id' => $task->job_id, 'client_id' => $task->client_id, 'warehouse_id' => $task->warehouse_id, 'source_type' => 'fulfilment', 'source_id' => $fulfilmentId, 'order_id' => $task->order_id, 'fulfilment_id' => $fulfilmentId, 'wave_id' => $task->wave_id]);
            $this->tasks->complete($packTask, ['billable_qty' => $created->count(), 'billable_uom' => 'label'], 'fulfilment:'.$fulfilmentId);

            return ['packages' => $created, 'event_id' => $event->eventId, 'lines' => $lines->all(), 'is_urgent' => $isUrgent];
        });
    }

    /** Handover: goods leave the warehouse; loaded pallets become a `load` task (WH-LOAD-PLT); outbound.dispatched tells OMS / TMS. */
    public function dispatch(int $fulfilmentId, int $palletCount, string $handedTo, ?int $shipmentId = null, ?int $userId = null): OutboundDispatch
    {
        $packages = Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentId)->get();
        if ($packages->isEmpty()) {
            throw new InvalidArgumentException(__('warehouse.outbound.errors.dispatch_after_pack'));
        }
        if (OutboundDispatch::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentId)->exists()) {
            throw new InvalidArgumentException(__('warehouse.outbound.errors.already_dispatched'));
        }
        $first = $packages->first();
        $this->refuseCancelledOrder($first->order_id);
        if ($this->exceptions->hasActiveHold('financial', $first->client_id, $first->order_id)) {
            // §3.8 #7: a financial hold lets the order be picked and packed, but nothing leaves the warehouse until Finance releases it.
            throw new RuleViolation('This order is under a financial hold — release it before dispatch.', 'warehouse.outbound.errors.financial_hold');
        }
        $task = WarehouseTask::query()->withoutGlobalScopes()->where('task_type', 'pick')->where('fulfilment_id', $fulfilmentId)->firstOrFail();

        return DB::transaction(function () use ($fulfilmentId, $palletCount, $handedTo, $shipmentId, $userId, $packages, $first, $task): OutboundDispatch {
            $dispatch = OutboundDispatch::query()->create([
                'fulfilment_id' => $fulfilmentId, 'order_id' => $first->order_id, 'job_id' => $first->job_id, 'client_id' => $first->client_id, 'warehouse_id' => $task->warehouse_id,
                'pallet_count' => $palletCount, 'package_count' => $packages->count(), 'handed_to' => $handedTo, 'shipment_id' => $shipmentId, 'dispatched_by' => $userId, 'dispatched_at' => now(),
            ]);

            if ($palletCount > 0) {
                $load = $this->tasks->create('load', ['job_id' => $first->job_id, 'client_id' => $first->client_id, 'warehouse_id' => $task->warehouse_id, 'source_type' => 'fulfilment', 'source_id' => $fulfilmentId, 'order_id' => $first->order_id, 'fulfilment_id' => $fulfilmentId]);
                $this->tasks->complete($load, ['billable_qty' => $palletCount, 'billable_uom' => 'pallet'], 'fulfilment:'.$fulfilmentId);
            }

            $this->outbox->publish(new OutboundDispatched([
                'order_id' => $first->order_id, 'fulfilment_id' => $fulfilmentId, 'job_id' => $first->job_id, 'client_id' => $first->client_id, 'warehouse_id' => $task->warehouse_id,
                'shipment_id' => $shipmentId, 'handed_to' => $handedTo, 'pallet_count' => $palletCount, 'package_count' => $packages->count(),
                'carton_labels' => $packages->pluck('carton_label')->all(), 'dispatched_by' => $userId, 'dispatched_at' => $dispatch->dispatched_at->toIso8601String(),
            ], $first->job_id, $first->client_id, 'fulfilment:'.$fulfilmentId));

            return $dispatch;
        });
    }

    /**
     * One entry per piece: a row with qty 3 becomes three rows without `qty`; qty < 1 counts as 1.
     *
     * @param  list<array<string, mixed>>  $packages
     * @return list<array<string, mixed>>
     */
    public static function expandPackages(array $packages): array
    {
        $expanded = [];
        foreach ($packages as $package) {
            $qty = max(1, (int) ($package['qty'] ?? 1));
            unset($package['qty']);
            for ($i = 0; $i < $qty; $i++) {
                $expanded[] = $package;
            }
        }

        return $expanded;
    }

    /**
     * 关闭任务 on the wave page (admin / warehouse_supervisor): the pick task of a CANCELLED order that was already started — the operator was
     * told to put the picked goods back. The task becomes `cancelled`, whatever the order still holds in active reservations is released
     * (stock.released), and the wave completes when nothing else is open. Picked quantities already left the units through the `pick`
     * movement; putting them back on hand is a stocktake adjustment, not done here (open question in HANDOFF.md 2026-09-22).
     */
    public function closeCancelledTask(WarehouseTask $task, ?int $userId = null): WarehouseTask
    {
        if ($task->task_type !== 'pick') {
            throw new RuleViolation("Task {$task->task_no} is not a pick task.", 'warehouse.outbound.errors.close_pick_only');
        }
        if (in_array($task->status, ['done', 'cancelled'], true)) {
            throw new RuleViolation("Task {$task->task_no} is already {$task->status}.", 'warehouse.tasks.not_pending', ['task_no' => $task->task_no]);
        }
        $order = DB::table('orders')->where('id', $task->order_id)->first(['order_no', 'operational_status']);
        if ($order === null || $order->operational_status !== 'cancelled') {
            throw new RuleViolation("Order {$order?->order_no} is not cancelled — confirm the picks instead.", 'warehouse.outbound.errors.order_not_cancelled', ['order' => $order?->order_no ?? '#'.$task->order_id]);
        }

        return DB::transaction(function () use ($task, $userId): WarehouseTask {
            $task = WarehouseTask::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->id);
            $reservationIds = StockReservation::query()->where('order_id', $task->order_id)->where('status', 'active')->pluck('id')->all();
            $released = $this->stock->release($task->order_id, null, 'order_cancelled');
            if ($released > 0) {
                $this->outbox->publish(new StockReleased([
                    'order_id' => $task->order_id, 'order_line_id' => null, 'job_id' => $task->job_id, 'client_id' => $task->client_id,
                    'qty' => $released, 'reason' => 'order_cancelled', 'reservation_ids' => $reservationIds, 'released_at' => now()->toIso8601String(),
                ], $task->job_id, $task->client_id, 'fulfilment:'.$task->fulfilment_id));
            }
            $task->forceFill(['status' => 'cancelled', 'cancel_reason' => 'order_cancelled', 'completed_by' => $userId])->save();
            $this->closeWaveIfDone($task->wave_id);

            return $task;
        });
    }

    /**
     * order.cancelled (OrderCancelledConsumer): pick tasks of the order nobody has started — no line confirmed yet — are cancelled and their
     * waves re-checked, so a wave released before the cancel can still complete. A started task keeps its lines for the supervisor's
     * 关闭任务 on the wave page. Returns how many tasks were cancelled.
     */
    public function cancelUnstartedPickTasks(int $orderId, string $reason = 'order_cancelled'): int
    {
        return DB::transaction(function () use ($orderId, $reason): int {
            $tasks = WarehouseTask::query()->withoutGlobalScopes()->where('task_type', 'pick')->where('order_id', $orderId)
                ->whereIn('status', ['pending', 'in_progress'])->whereDoesntHave('lines', fn ($q) => $q->whereNotNull('confirmed_at'))
                ->lockForUpdate()->get();
            foreach ($tasks as $task) {
                $task->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();
                $this->closeWaveIfDone($task->wave_id);
            }

            return $tasks->count();
        });
    }

    /** Order ids among $orderIds whose operational_status is `cancelled` (read-only look at orders, for the board and the wave page). */
    public static function cancelledOrderIds(iterable $orderIds): array
    {
        $ids = collect($orderIds)->filter()->unique()->values();

        return $ids->isEmpty() ? [] : DB::table('orders')->whereIn('id', $ids)->where('operational_status', 'cancelled')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function refuseCancelledOrder(?int $orderId): void
    {
        $order = $orderId ? DB::table('orders')->where('id', $orderId)->first(['order_no', 'operational_status']) : null;
        if ($order !== null && $order->operational_status === 'cancelled') {
            throw new RuleViolation("Order {$order->order_no} is cancelled: it is not picked, packed or dispatched.", 'warehouse.outbound.errors.order_cancelled', ['order' => $order->order_no]);
        }
    }

    /** A wave is complete when no pick task of it is still open (`done` or `cancelled` both count). */
    private function closeWaveIfDone(?int $waveId): void
    {
        if ($waveId && WarehouseTask::query()->withoutGlobalScopes()->where('wave_id', $waveId)->where('task_type', 'pick')->whereNotIn('status', ['done', 'cancelled'])->doesntExist()) {
            Wave::query()->whereKey($waveId)->update(['status' => 'completed']);
        }
    }

    /**
     * How a pick line is billed (lead decision 2026-09-14, CHANGE_REQUESTS #121): a pallet unit is a pallet pick only when the
     * line took everything the pallet still held — the pallet left whole. Taking part of a pallet ("一托上十几箱，一箱一箱出") is
     * carton picks, banded on the pallet's weight spread over the cartons it held when received. Carton units are carton picks
     * with the ASN line's per-carton weight, as before. The pick movement written by confirmPick() tells which case this was.
     *
     * @return array{string, ?float} [unit_type for Billing (pallet | carton), unit weight in kg or null when unknown]
     */
    private function pickBilling(StockUnit $unit, WarehouseTaskLine $line, WarehouseTask $task): array
    {
        $asnLine = $unit->asnLine;
        $cartonWeight = $asnLine?->weight_kg !== null ? round((float) $asnLine->weight_kg / max(1, (int) $asnLine->expected_cartons), 3) : null;
        if ($unit->unit_type !== 'pallet') {
            return ['carton', $cartonWeight];
        }

        $pick = StockLedgerEntry::query()->where('stock_unit_id', $unit->id)->where('movement_type', 'pick')
            ->where('source_type', 'task')->where('source_id', $task->id)->orderByDesc('id')->first(['qty_before', 'qty_after']);
        $wholePallet = $pick === null || (int) $pick->qty_after === 0; // no movement (legacy data) → the old behaviour
        if ($wholePallet) {
            return ['pallet', $unit->weight_kg !== null ? (float) $unit->weight_kg : null];
        }

        $received = (int) (StockLedgerEntry::query()->where('stock_unit_id', $unit->id)->where('movement_type', 'receipt')->orderBy('id')->value('qty_after') ?? 0);
        $cartonsOnPallet = max(1, $received > 0 ? $received : (int) $pick->qty_before);
        $perCarton = $unit->weight_kg !== null ? round((float) $unit->weight_kg / $cartonsOnPallet, 3) : $cartonWeight;

        return ['carton', $perCarton];
    }
}
