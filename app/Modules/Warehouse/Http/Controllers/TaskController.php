<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\TaskService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** B12 core: VAS / devanning / labour tasks — create against an ASN, complete with billable quantity → task.completed. */
class TaskController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Enums::TASK_STATUSES)], 'task_type' => ['nullable', Rule::in(Enums::TASK_TYPES)]]);

        return view('warehouse::tasks.index', [
            'tasks' => WarehouseTask::query()->with(['asn', 'container'])
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['task_type'] ?? null, fn ($q, $v) => $q->where('task_type', $v))
                ->when(WarehouseContext::currentId(), fn ($q, $v) => $q->where('warehouse_id', $v))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'statuses' => Enums::TASK_STATUSES,
            'types' => Enums::TASK_TYPES,
            'uoms' => Enums::BILLABLE_UOMS,
        ]);
    }

    /** 作业登记: only VAS types are created by hand; the record binds to an ASN (inbound VAS) or an order (outbound wrap, per-order labour). */
    public function create(Request $request): View
    {
        return view('warehouse::tasks.create', [
            'asns' => Asn::query()->with('containers', 'client')->whereIn('status', ['booked', 'arrived', 'receiving', 'putaway'])->orderByDesc('id')->limit(200)->get(),
            'orders' => Order::query()->with('client')->whereNotIn('operational_status', ['cancelled'])->orderByDesc('id')->limit(200)->get(['id', 'order_no', 'client_id', 'job_id', 'operational_status']),
            'types' => Enums::VAS_TASK_TYPES,
            'selectedAsn' => $request->integer('asn_id') ?: null,
            'selectedOrder' => $request->integer('order_id') ?: null,
            'selectedType' => in_array($request->string('task_type')->toString(), Enums::VAS_TASK_TYPES, true) ? $request->string('task_type')->toString() : 'devanning',
        ]);
    }

    public function store(Request $request, TaskService $tasks): RedirectResponse
    {
        $data = $request->validate([
            'asn_id' => ['nullable', 'integer', 'required_without:order_id', Rule::exists('asns', 'id')],
            'order_id' => ['nullable', 'integer', 'required_without:asn_id', Rule::exists('orders', 'id')],
            'container_id' => ['nullable', 'integer', Rule::exists('containers', 'id')],
            'task_type' => ['required', Rule::in(Enums::VAS_TASK_TYPES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['asn_id.required_without' => __('warehouse.tasks.source_required'), 'order_id.required_without' => __('warehouse.tasks.source_required'), 'task_type.in' => __('warehouse.tasks.type_not_manual')]);

        if (! empty($data['asn_id'])) {
            $asn = Asn::query()->findOrFail($data['asn_id']);
            $attributes = [
                'job_id' => $asn->job_id, 'client_id' => $asn->client_id, 'warehouse_id' => $asn->warehouse_id,
                'source_type' => ! empty($data['container_id']) ? 'container' : 'asn', 'source_id' => $data['container_id'] ?? $asn->id,
                'asn_id' => $asn->id, 'container_id' => $data['container_id'] ?? null, 'order_id' => $data['order_id'] ?? null,
            ];
        } else {
            // Outbound VAS (缠膜打带 out, per-order labour): the order is the source, so WH-WRAP-OUT-PLT matches (BillingSeeder source_type order).
            $order = Order::query()->findOrFail($data['order_id']);
            $attributes = [
                'job_id' => $order->job_id, 'client_id' => $order->client_id,
                'warehouse_id' => WarehouseContext::currentId() ?? Warehouse::query()->where('active', true)->orderBy('id')->value('id'), // orders carry no warehouse; the operator's current warehouse applies
                'source_type' => 'order', 'source_id' => $order->id, 'order_id' => $order->id,
            ];
        }

        $task = $tasks->create($data['task_type'], $attributes + ['notes' => $data['notes'] ?? null]);

        return redirect()->route('warehouse.tasks.index')->with('status', __('warehouse.tasks.created', ['task_no' => $task->task_no]));
    }

    public function complete(Request $request, WarehouseTask $task, TaskService $tasks): RedirectResponse
    {
        // System-written records (pick / pack / load / return inspection …) are completed by their own operation, never here — the generic
        // 完成 button used to bypass pick confirmation (tester feedback #6, 2026-09-10).
        if (! in_array($task->task_type, Enums::VAS_TASK_TYPES, true)) {
            return back()->withErrors(['task' => __('warehouse.tasks.system_task')]);
        }
        if (in_array($task->status, ['done', 'cancelled'], true)) {
            return back()->withErrors(['task' => __('warehouse.tasks.not_pending', ['task_no' => $task->task_no])]);
        }

        $data = $request->validate([
            'billable_qty' => ['nullable', 'numeric', 'min:0'],
            'billable_uom' => ['nullable', Rule::in(Enums::BILLABLE_UOMS)],
            'hours_business' => ['nullable', 'numeric', 'min:0'],
            'hours_after_hours' => ['nullable', 'numeric', 'min:0'],
            'scan_count' => ['nullable', 'integer', 'min:0'],
            'serials' => ['nullable', 'string', 'max:20000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        // Devanning is always one container; default the billing quantity so the operator cannot forget it.
        if ($task->task_type === 'devanning') {
            $data += ['billable_qty' => 1, 'billable_uom' => 'container'];
        }

        if (! empty($data['serials'])) {
            $data['serials'] = preg_split('/[\r\n,;]+/', $data['serials']) ?: [];
        }

        $tasks->complete($task, array_filter($data, fn ($v) => $v !== null && $v !== ''), $task->asn?->asn_no);

        return back()->with('status', __('warehouse.tasks.completed', ['task_no' => $task->task_no]));
    }

    /** Cancels a hand-made record (VAS entered by mistake, or a legacy 收货/上架/移库 task that never did anything). System tasks are untouchable. */
    public function cancel(Request $request, WarehouseTask $task, TaskService $tasks): RedirectResponse
    {
        if (in_array($task->task_type, Enums::SYSTEM_TASK_TYPES, true)) {
            return back()->withErrors(['task' => __('warehouse.tasks.system_task')]);
        }
        if (in_array($task->status, ['done', 'cancelled'], true)) {
            return back()->withErrors(['task' => __('warehouse.tasks.not_pending', ['task_no' => $task->task_no])]);
        }
        $data = $request->validate(['cancel_reason' => ['nullable', 'string', 'max:255']]);
        $tasks->cancel($task, $data['cancel_reason'] ?? null);

        return back()->with('status', __('warehouse.tasks.cancelled', ['task_no' => $task->task_no]));
    }
}
