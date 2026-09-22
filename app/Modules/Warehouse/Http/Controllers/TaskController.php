<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Models\Order;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Container;
use App\Modules\Warehouse\Models\PhysicalContainer;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\PhysicalContainerService;
use App\Modules\Warehouse\Services\TaskService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** B12 core: VAS / devanning / labour tasks — create against an ASN, an order or a shared physical container (#122), complete with billable quantity → task.completed. */
class TaskController extends Controller
{
    /** The completion fields of a VAS record — shared by 完成 on the list and 现在完成 on the create page (audit 2026-09-22 INBOUND-07, CR #141). */
    private const COMPLETION_RULES = [
        'billable_qty' => ['nullable', 'numeric', 'min:0'],
        'billable_uom' => ['nullable', 'in:container,pallet,carton,scan,man_hour,cbm,label'],
        'hours_business' => ['nullable', 'numeric', 'min:0'],
        'hours_after_hours' => ['nullable', 'numeric', 'min:0'],
        'scan_count' => ['nullable', 'integer', 'min:0'],
        'serials' => ['nullable', 'string', 'max:20000'],
    ];

    /**
     * The Chinese refusal when the billable figure a task type needs is missing: wrap / waste → billable_qty > 0; labour / vas_other →
     * hours_business + hours_after_hours > 0; scanning → scan_count > 0 or serials. Devanning defaults to one container (null = fine).
     *
     * @param  array<string, mixed>  $input
     */
    public static function completionError(string $taskType, array $input): ?string
    {
        $hours = (float) ($input['hours_business'] ?? 0) + (float) ($input['hours_after_hours'] ?? 0);

        return match ($taskType) {
            'wrap', 'waste' => (float) ($input['billable_qty'] ?? 0) > 0 ? null : __('warehouse.tasks.quantity_required.qty', ['type' => __('warehouse.task_types.'.$taskType)]),
            'labour', 'vas_other' => $hours > 0 ? null : __('warehouse.tasks.quantity_required.hours', ['type' => __('warehouse.task_types.'.$taskType)]),
            'scanning' => (int) ($input['scan_count'] ?? 0) > 0 || filled($input['serials'] ?? null) ? null : __('warehouse.tasks.quantity_required.scans'),
            default => null,
        };
    }

    /**
     * The validated completion fields as TaskService::complete wants them: empty values dropped, serials split, devanning defaulted to one container.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function completionData(array $data, string $taskType): array
    {
        $completion = array_intersect_key($data, array_flip([...array_keys(self::COMPLETION_RULES), 'notes']));
        if ($taskType === 'devanning') {
            $completion += ['billable_qty' => 1, 'billable_uom' => 'container'];
        }
        if (! empty($completion['serials'])) {
            $completion['serials'] = preg_split('/[\r\n,;]+/', $completion['serials']) ?: [];
        }

        return array_filter($completion, fn ($v) => $v !== null && $v !== '');
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Enums::TASK_STATUSES)], 'task_type' => ['nullable', Rule::in(Enums::TASK_TYPES)]]);

        return view('warehouse::tasks.index', [
            'tasks' => WarehouseTask::query()->with(['asn', 'container', 'physicalContainer'])
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

    /** 作业登记: only VAS types are created by hand; the record binds to an ASN (inbound VAS), an order (outbound wrap, per-order labour) or a physical container (box-level devanning, #122). */
    public function create(Request $request): View
    {
        // Audit 2026-09-22 INBOUND-08: the 柜号 options of the ASN the page opens on are rendered here (not only by the page script), and the
        // ASN's only unlinked container is preselected — a devanning task saved without its container raised no devanning fee.
        $asnId = (int) old('asn_id', $request->integer('asn_id')) ?: null;
        $initialAsn = $asnId ? Asn::query()->with('containers')->find($asnId) : null;
        $initialContainers = $initialAsn?->containers ?? collect();
        $unlinked = $initialContainers->reject(fn (Container $c) => $c->isLinked())->values();
        $requestedContainer = (int) old('container_id', $request->integer('container_id')) ?: null;

        return view('warehouse::tasks.create', [
            'asns' => Asn::query()->with('containers', 'client')->whereIn('status', ['booked', 'arrived', 'receiving', 'putaway'])->orderByDesc('id')->limit(200)->get(),
            'initialContainers' => $initialContainers,
            'selectedContainer' => $requestedContainer ?? ($unlinked->count() === 1 ? (int) $unlinked->first()->id : null),
            'orders' => Order::query()->with('client')->whereNotIn('operational_status', ['cancelled'])->orderByDesc('id')->limit(200)->get(['id', 'order_no', 'client_id', 'job_id', 'operational_status']),
            'physicalContainers' => PhysicalContainer::query()->with('warehouse')->whereNull('devanning_task_id')->where('status', '!=', 'devanned')->orderByDesc('id')->limit(100)->get(),
            'types' => Enums::VAS_TASK_TYPES,
            'uoms' => Enums::BILLABLE_UOMS,
            'selectedAsn' => $request->integer('asn_id') ?: null,
            'selectedOrder' => $request->integer('order_id') ?: null,
            'selectedPhysicalContainer' => $request->integer('physical_container_id') ?: null,
            'selectedType' => in_array($request->string('task_type')->toString(), Enums::VAS_TASK_TYPES, true) ? $request->string('task_type')->toString() : 'devanning',
        ]);
    }

    public function store(Request $request, TaskService $tasks, PhysicalContainerService $boxes): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'asn_id' => ['nullable', 'integer', 'required_without_all:order_id,physical_container_id', Rule::exists('asns', 'id')],
            'order_id' => ['nullable', 'integer', 'required_without_all:asn_id,physical_container_id', Rule::exists('orders', 'id')],
            'physical_container_id' => ['nullable', 'integer', Rule::exists('physical_containers', 'id')],
            'container_id' => ['nullable', 'integer', Rule::exists('containers', 'id')],
            'task_type' => ['required', Rule::in(Enums::VAS_TASK_TYPES)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'complete_now' => ['nullable', 'boolean'], // 现在完成 (audit 2026-09-22 INBOUND-07, CR #141): create + complete in one step
        ] + self::COMPLETION_RULES, [
            'asn_id.required_without_all' => __('warehouse.tasks.source_required'), 'order_id.required_without_all' => __('warehouse.tasks.source_required'),
            'task_type.in' => __('warehouse.tasks.type_not_manual'),
        ]);
        $validator->after(function ($v) use ($request) {
            if ($request->boolean('complete_now') && ($error = self::completionError((string) $request->input('task_type'), $request->all())) !== null) {
                $v->errors()->add('billable_qty', $error);
            }
        });
        $data = $validator->validate();
        $completeNow = (bool) ($data['complete_now'] ?? false);
        $completion = $completeNow ? $this->completionData($data, (string) $data['task_type']) : null;

        if (! empty($data['physical_container_id'])) {
            // 拼柜 / 物理柜 (CHANGE_REQUESTS #122): the ONE devanning task of the box; job / client NULL, members carry the split.
            if ($data['task_type'] !== 'devanning') {
                return back()->withInput()->withErrors(['task_type' => __('warehouse.physical_containers.errors.box_task_devanning_only')]);
            }
            $box = PhysicalContainer::query()->findOrFail($data['physical_container_id']);
            try {
                $task = DB::transaction(function () use ($boxes, $tasks, $box, $data, $completion): WarehouseTask {
                    $task = $boxes->registerDevanning($box, $data['notes'] ?? null);

                    return $completion === null ? $task : $tasks->complete($task, $completion, $box->container_no);
                });
            } catch (InvalidArgumentException $e) {
                return back()->withInput()->withErrors(['physical_container_id' => RuleViolation::display($e)]);
            }

            return redirect()->route('warehouse.physical_containers.show', $box)->with('status', __($completion === null ? 'warehouse.tasks.created' : 'warehouse.tasks.created_completed', ['task_no' => $task->task_no]));
        }

        if (! empty($data['asn_id'])) {
            $asn = Asn::query()->findOrFail($data['asn_id']);
            if ($data['task_type'] === 'devanning' && empty($data['container_id']) && $asn->containers()->exists()) {
                // Every WH-DEVAN-* rule matches on the container's size × unpack mode: without the container the task completes with no fee (audit 2026-09-22 INBOUND-08).
                return back()->withInput()->withErrors(['container_id' => __('warehouse.tasks.container_required')]);
            }
            $container = ! empty($data['container_id']) ? Container::query()->findOrFail($data['container_id']) : null;
            if ($container !== null && (int) $container->asn_id !== (int) $asn->id) {
                return back()->withInput()->withErrors(['container_id' => __('warehouse.tasks.container_not_on_asn')]);
            }
            if ($container?->isLinked() && $data['task_type'] === 'devanning') {
                // The row sits in a shared box: devanning is registered once on the box page and allocated over its members.
                return back()->withInput()->withErrors(['container_id' => __('warehouse.tasks.container_linked_use_box', ['no' => $container->container_no])]);
            }
            $attributes = [
                'job_id' => $asn->job_id, 'client_id' => $asn->client_id, 'warehouse_id' => $asn->warehouse_id,
                'source_type' => $container !== null ? 'container' : 'asn', 'source_id' => $container?->id ?? $asn->id,
                'asn_id' => $asn->id, 'container_id' => $container?->id, 'order_id' => $data['order_id'] ?? null,
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

        $task = DB::transaction(function () use ($tasks, $data, $attributes, $completion): WarehouseTask {
            $task = $tasks->create($data['task_type'], $attributes + ['notes' => $data['notes'] ?? null]);

            return $completion === null ? $task : $tasks->complete($task, $completion, $task->asn?->asn_no);
        });

        return redirect()->route('warehouse.tasks.index')->with('status', __($completion === null ? 'warehouse.tasks.created' : 'warehouse.tasks.created_completed', ['task_no' => $task->task_no]));
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

        $validator = Validator::make($request->all(), self::COMPLETION_RULES + ['notes' => ['nullable', 'string', 'max:1000']]);
        // Audit 2026-09-22 INBOUND-07 (CR #141): a VAS record completed with an empty quantity billed nothing and warned nobody — the billable figure is required per type.
        $validator->after(function ($v) use ($request, $task) {
            if (($error = self::completionError($task->task_type, $request->all())) !== null) {
                $v->errors()->add('billable_qty', $error);
            }
        });
        $data = $validator->validate();

        try {
            $tasks->complete($task, $this->completionData($data, $task->task_type), $task->asn?->asn_no);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['task' => RuleViolation::display($e)]);
        }

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
        if ($task->isBoxLevel()) {
            PhysicalContainer::query()->where('devanning_task_id', $task->id)->update(['devanning_task_id' => null]); // the box may be registered again
        }

        return back()->with('status', __('warehouse.tasks.cancelled', ['task_no' => $task->task_no]));
    }
}
