<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\Asn;
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

    public function create(Request $request): View
    {
        return view('warehouse::tasks.create', [
            'asns' => Asn::query()->with('containers', 'client')->whereIn('status', ['booked', 'arrived', 'receiving', 'putaway'])->orderByDesc('id')->limit(200)->get(),
            'types' => array_values(array_diff(Enums::TASK_TYPES, ['pick', 'pack', 'return_inspection'])),
            'selectedAsn' => $request->integer('asn_id') ?: null,
        ]);
    }

    public function store(Request $request, TaskService $tasks): RedirectResponse
    {
        $data = $request->validate([
            'asn_id' => ['required', 'integer', Rule::exists('asns', 'id')],
            'container_id' => ['nullable', 'integer', Rule::exists('containers', 'id')],
            'task_type' => ['required', Rule::in(Enums::TASK_TYPES)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $asn = Asn::query()->findOrFail($data['asn_id']);

        $task = $tasks->create($data['task_type'], [
            'job_id' => $asn->job_id, 'client_id' => $asn->client_id, 'warehouse_id' => $asn->warehouse_id,
            'source_type' => $data['container_id'] ? 'container' : 'asn', 'source_id' => $data['container_id'] ?? $asn->id,
            'asn_id' => $asn->id, 'container_id' => $data['container_id'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('warehouse.tasks.index')->with('status', __('warehouse.tasks.created', ['task_no' => $task->task_no]));
    }

    public function complete(Request $request, WarehouseTask $task, TaskService $tasks): RedirectResponse
    {
        abort_if($task->status === 'done', 409);

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
}
