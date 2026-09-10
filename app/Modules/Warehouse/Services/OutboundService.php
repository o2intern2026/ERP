<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Events\OutboundDispatched;
use App\Modules\Warehouse\Events\OutboundPacked;
use App\Modules\Warehouse\Models\OutboundDispatch;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\StockReservation;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\WarehouseTaskLine;
use App\Modules\Warehouse\Models\Wave;
use App\Support\Contracts\ExceptionService;
use App\Support\Exceptions\RuleViolation;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * B4 outbound: wave → pick tasks (location order) → confirm picks (ledger `pick`, reservations consumed, Pick Short
 * exception) → pack (packages + outbound.packed, the billing input) → dispatch (handover, load task, outbound.dispatched).
 * Reads OMS's fulfilments / orders read-only; order status is OMS's job after our events (§4.3 rule 6).
 */
final class OutboundService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly StockLedger $ledger,
        private readonly ExceptionService $exceptions,
        private readonly OutboxPublisher $outbox,
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
                ->whereNotIn('fulfilments.id', WarehouseTask::query()->withoutGlobalScopes()->where('task_type', 'pick')->whereNotNull('fulfilment_id')->select('fulfilment_id'))
                ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('orders.client_id', $v))
                ->when($filters['requested_date'] ?? null, fn ($q, $v) => $q->whereDate('orders.requested_date', '<=', $v))
                ->when(! empty($filters['order_ids']), fn ($q) => $q->whereIn('orders.id', $filters['order_ids']))
                ->orderBy('orders.requested_date')->orderBy('fulfilments.id')
                ->get(['fulfilments.id as fulfilment_id', 'orders.id as order_id', 'orders.job_id', 'orders.client_id']);

            if ($fulfilments->isEmpty()) {
                throw new InvalidArgumentException('No allocated fulfilments to release for these filters.');
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
            throw new InvalidArgumentException("Picked quantity must be between 0 and {$line->required_qty}.");
        }
        $task = $line->task;
        if ($task->status === 'done' || $line->confirmed_at !== null) {
            throw new InvalidArgumentException('This pick line is already confirmed.');
        }

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
     *
     * @param  list<array{package_type:string, weight_kg:float, length_mm?:?int, width_mm?:?int, height_mm?:?int}>  $packages
     */
    public function pack(int $fulfilmentId, array $packages, ?int $userId = null): array
    {
        $task = WarehouseTask::query()->withoutGlobalScopes()->where('task_type', 'pick')->where('fulfilment_id', $fulfilmentId)->with('lines.stockUnit.asnLine')->firstOrFail();
        if ($task->status !== 'done') {
            throw new InvalidArgumentException('Pack after every pick line of the fulfilment is confirmed.');
        }
        if (Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentId)->exists()) {
            throw new InvalidArgumentException('This fulfilment is already packed.');
        }
        if ($packages === []) {
            throw new InvalidArgumentException('At least one package is needed.');
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

            $lines = $task->lines->filter(fn ($l) => $l->completed_qty > 0)->map(function (WarehouseTaskLine $l) {
                $unit = $l->stockUnit;
                $unitWeight = $unit->unit_type === 'pallet'
                    ? ($unit->weight_kg !== null ? (float) $unit->weight_kg : null)
                    : ($unit->asnLine?->weight_kg !== null ? round((float) $unit->asnLine->weight_kg / max(1, (int) $unit->asnLine->expected_cartons), 3) : null);

                return ['order_line_id' => $l->order_line_id, 'stock_unit_id' => $l->stock_unit_id, 'unit_type' => $unit->unit_type, 'qty' => $l->completed_qty, 'unit_weight_kg' => $unitWeight];
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
            throw new InvalidArgumentException('Dispatch after packing.');
        }
        if (OutboundDispatch::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentId)->exists()) {
            throw new InvalidArgumentException('This fulfilment was already dispatched.');
        }
        $first = $packages->first();
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

    private function closeWaveIfDone(?int $waveId): void
    {
        if ($waveId && WarehouseTask::query()->withoutGlobalScopes()->where('wave_id', $waveId)->where('task_type', 'pick')->where('status', '!=', 'done')->doesntExist()) {
            Wave::query()->whereKey($waveId)->update(['status' => 'completed']);
        }
    }
}
