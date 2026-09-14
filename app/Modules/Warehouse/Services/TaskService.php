<?php

namespace App\Modules\Warehouse\Services;

use App\Modules\Warehouse\Events\TaskCompleted;
use App\Modules\Warehouse\Models\PhysicalContainer;
use App\Modules\Warehouse\Models\ScanRecord;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Facades\DB;

/** B12 core: tasks are the execution record; completing one emits task.completed for Billing (ERP_PLAN §4.3 VAS). */
final class TaskService
{
    public function __construct(private readonly OutboxPublisher $outbox) {}

    /** @param array<string, mixed> $attributes warehouse_tasks columns except task_no / status */
    public function create(string $taskType, array $attributes): WarehouseTask
    {
        return DB::transaction(fn () => WarehouseTask::query()->create($attributes + [
            'task_no' => DocumentNumbers::next(WarehouseTask::query()->withoutGlobalScopes(), 'task_no', 'TSK'),
            'task_type' => $taskType,
            'status' => 'pending',
        ]));
    }

    /** Cancels a pending / in-progress task without a billing event (legacy hand-made 收货/上架/移库 records, mistaken VAS entries). */
    public function cancel(WarehouseTask $task, ?string $reason = null): WarehouseTask
    {
        $task->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();

        return $task;
    }

    /**
     * @param  array{billable_qty?:float, billable_uom?:string, hours_business?:float, hours_after_hours?:float, scan_count?:int, notes?:string}  $completion
     */
    public function complete(WarehouseTask $task, array $completion = [], ?string $correlationId = null): WarehouseTask
    {
        return DB::transaction(function () use ($task, $completion, $correlationId): WarehouseTask {
            $serials = array_values(array_unique(array_filter(array_map('trim', $completion['serials'] ?? []))));
            foreach ($serials as $serial) {
                ScanRecord::query()->create(['task_id' => $task->id, 'serial_no' => $serial, 'scanned_by' => auth()->id(), 'scanned_at' => now()]);
            }
            if ($serials !== []) {
                $completion['scan_count'] = ($completion['scan_count'] ?? 0) + count($serials);
                $completion['billable_qty'] ??= $completion['scan_count'];
                $completion['billable_uom'] ??= 'scan';
            }

            $task->fill(array_intersect_key($completion, array_flip(['billable_qty', 'billable_uom', 'hours_business', 'hours_after_hours', 'notes'])));
            $task->status = 'done';
            $task->completed_at = now();
            $task->completed_by = auth()->id();
            $task->started_at ??= now();

            if ($task->isBoxLevel()) {
                // 拼柜 (CHANGE_REQUESTS #122): the ONE devanning task of a shared box — no Job / client on the envelope, the members'
                // shares travel in the payload and Billing allocates the fee per member Job. Same transaction as the task write.
                $box = PhysicalContainer::query()->findOrFail($task->physical_container_id);
                ['payload' => $payload] = app(PhysicalContainerService::class)->completeDevanning($box, $task, $completion['scan_count'] ?? null);
                $event = new TaskCompleted($payload, jobId: null, clientId: null, correlationId: $correlationId ?? $box->container_no);
            } else {
                $event = new TaskCompleted(self::eventPayload($task, $completion['scan_count'] ?? null), jobId: $task->job_id, clientId: $task->client_id, correlationId: $correlationId);
            }

            $task->billable_event_id = $event->eventId;
            $task->save();
            $this->outbox->publish($event);

            return $task;
        });
    }

    /**
     * The `task.completed` payload of contracts/events.md for one task (no side effects). Shared with PhysicalContainerService,
     * which re-emits a box-level devanning with a higher activity_version on 重算分摊.
     *
     * @return array<string, mixed>
     */
    public static function eventPayload(WarehouseTask $task, ?int $scanCount = null): array
    {
        $container = $task->container;

        return [
            'task_id' => $task->id,
            'task_no' => $task->task_no,
            'task_type' => $task->task_type,
            'job_id' => $task->job_id,
            'client_id' => $task->client_id,
            'warehouse_id' => $task->warehouse_id,
            'source_type' => $task->source_type,
            'source_id' => $task->source_id,
            'order_id' => $task->order_id,
            'fulfilment_id' => $task->fulfilment_id,
            'asn_id' => $task->asn_id,
            'container_id' => $task->container_id,
            'asn' => $task->asn ? ['asn_no' => $task->asn->asn_no, 'inbound_type' => $task->asn->inbound_type] : null, // Billing: unload fee only for loose trucks (charge-codes.md #9)
            'container' => $container ? ['size' => $container->size, 'unpack_mode' => $container->unpack_mode, 'line_count' => $container->line_count, 'gross_weight_kg' => $container->gross_weight_kg !== null ? (float) $container->gross_weight_kg : null] : null,
            'billable_qty' => $task->billable_qty !== null ? (float) $task->billable_qty : null,
            'billable_uom' => $task->billable_uom,
            'hours_business' => $task->hours_business !== null ? (float) $task->hours_business : null,
            'hours_after_hours' => $task->hours_after_hours !== null ? (float) $task->hours_after_hours : null,
            'scan_count' => $scanCount,
            'started_at' => $task->started_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'completed_by' => $task->completed_by,
        ];
    }
}
