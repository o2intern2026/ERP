<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Charge;
use App\Modules\Warehouse\Models\Container;
use App\Modules\Warehouse\Models\PhysicalContainer;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\PhysicalContainerService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Contracts\RateService;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * 物理柜 / 拼柜 (CHANGE_REQUESTS #122): the coordinator's linking screen — register the box, link the container rows of the
 * ASNs that share it (any client, same warehouse), register its ONE devanning task, mark it arrived (cartage), re-split
 * (重算分摊). Staff roles only (admin / customer_service / warehouse_supervisor); the portal never reaches these pages.
 */
class PhysicalContainerController extends Controller
{
    /** Who sees the members' devanning / cartage charges on the box page — the same roles as the Job workbench charge panel. */
    private const CHARGE_ROLES = ['admin', 'finance', 'customer_service', 'dispatcher'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'container_no' => ['nullable', 'string', 'max:20'], 'status' => ['nullable', Rule::in(Enums::PHYSICAL_CONTAINER_STATUSES)],
            'consolidation' => ['nullable', Rule::in(Enums::CONSOLIDATIONS)], 'sideloader' => ['nullable', 'boolean'],
        ]);

        return view('warehouse::physical_containers.index', [
            'boxes' => PhysicalContainer::query()->with(['warehouse', 'devanningTask'])->withCount('members')
                ->when($filters['container_no'] ?? null, fn ($q, $v) => $q->where('container_no', 'like', '%'.strtoupper(trim($v)).'%'))
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['consolidation'] ?? null, fn ($q, $v) => $q->where('consolidation', $v))
                ->when($filters['sideloader'] ?? null, fn ($q) => $q->where('sideloader_required', true))
                ->when(WarehouseContext::currentId(), fn ($q, $v) => $q->where('warehouse_id', $v))
                ->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'statuses' => Enums::PHYSICAL_CONTAINER_STATUSES,
            'consolidations' => Enums::CONSOLIDATIONS,
        ]);
    }

    public function create(Request $request): View
    {
        // The ASN page's 关联物理柜 shortcut pre-fills the box from the container row (number, size, mode, weight, warehouse).
        $prefill = $request->validate([
            'container_no' => ['nullable', 'string', 'max:20'], 'warehouse_id' => ['nullable', 'integer'], 'size' => ['nullable', Rule::in(Enums::CONTAINER_SIZES)],
            'unpack_mode' => ['nullable', Rule::in(Enums::UNPACK_MODES)], 'gross_weight_kg' => ['nullable', 'numeric'], 'eta_date' => ['nullable', 'date'],
        ]);

        return view('warehouse::physical_containers.create', [
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'prefill' => $prefill + ['warehouse_id' => $prefill['warehouse_id'] ?? WarehouseContext::currentId()],
            'sizes' => Enums::CONTAINER_SIZES,
            'modes' => Enums::UNPACK_MODES,
            'bases' => Enums::ALLOCATION_BASES,
        ]);
    }

    public function store(Request $request, PhysicalContainerService $boxes): RedirectResponse
    {
        $data = $request->validate([
            'container_no' => ['required', 'string', 'max:20'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'size' => ['required', Rule::in(Enums::CONTAINER_SIZES)],
            'unpack_mode' => ['required', Rule::in(Enums::UNPACK_MODES)],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'allocation_basis' => ['nullable', Rule::in(Enums::ALLOCATION_BASES)],
            'cartage_by_us' => ['nullable', 'boolean'],
            'sideloader_required' => ['nullable', 'boolean'],
            'eta_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $box = $boxes->create($data + ['created_by' => auth()->id()]);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['container_no' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.physical_containers.show', $box)->with('status', __('warehouse.physical_containers.created', ['no' => $box->container_no]));
    }

    /** 修改物理柜 (CR #141): the header form, only while the box has not arrived / emitted anything. */
    public function edit(PhysicalContainer $box): View|RedirectResponse
    {
        if ($box->arrived_at !== null || $box->hasEmitted()) {
            return redirect()->route('warehouse.physical_containers.show', $box)->withErrors(['edit' => __('warehouse.physical_containers.errors.locked_after_arrival', ['no' => $box->container_no])]);
        }

        return view('warehouse::physical_containers.edit', [
            'box' => $box->load('warehouse')->loadCount('members'),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'sizes' => Enums::CONTAINER_SIZES,
            'modes' => Enums::UNPACK_MODES,
            'bases' => Enums::ALLOCATION_BASES,
        ]);
    }

    public function update(Request $request, PhysicalContainer $box, PhysicalContainerService $boxes): RedirectResponse
    {
        $data = $request->validate([
            'container_no' => ['required', 'string', 'max:20'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'size' => ['required', Rule::in(Enums::CONTAINER_SIZES)],
            'unpack_mode' => ['required', Rule::in(Enums::UNPACK_MODES)],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'allocation_basis' => ['required', Rule::in(Enums::ALLOCATION_BASES)],
            'cartage_by_us' => ['nullable', 'boolean'],
            'sideloader_required' => ['nullable', 'boolean'],
            'eta_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['cartage_by_us'] = (bool) ($data['cartage_by_us'] ?? false);
        $data['sideloader_required'] = (bool) ($data['sideloader_required'] ?? false);
        $data += ['eta_date' => null, 'gross_weight_kg' => null, 'notes' => null];

        try {
            $boxes->update($box, $data);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['edit' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.physical_containers.show', $box)->with('status', __('warehouse.physical_containers.updated', ['no' => $box->fresh()->container_no]));
    }

    public function destroy(PhysicalContainer $box, PhysicalContainerService $boxes): RedirectResponse
    {
        $no = $box->container_no;
        try {
            $boxes->delete($box);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['edit' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.physical_containers.index')->with('status', __('warehouse.physical_containers.deleted', ['no' => $no]));
    }

    /** 登记到港 confirmation (CR #141): the cartage / sideloader lines the event will raise, per member share, before anything is published. */
    public function arriveConfirm(PhysicalContainer $box, PhysicalContainerService $boxes, RateService $rates): View|RedirectResponse
    {
        if ($box->arrived_at !== null) {
            return redirect()->route('warehouse.physical_containers.show', $box)->withErrors(['arrive' => __('warehouse.physical_containers.errors.already_arrived', ['no' => $box->container_no])]);
        }
        $box->load(['warehouse', 'members.asn' => fn ($q) => $q->withoutGlobalScopes()->with(['client', 'job'])]);
        $preview = $boxes->arrivalPreview($box, $rates);

        return view('warehouse::physical_containers.arrive', ['box' => $box, 'preview' => $preview, 'jobs' => $box->members->mapWithKeys(fn ($m) => [(int) $m->job_id => $m->asn])]);
    }

    public function show(Request $request, PhysicalContainer $box, PhysicalContainerService $boxes): View
    {
        $box->load(['warehouse', 'devanningTask', 'createdBy', 'members.asn' => fn ($q) => $q->withoutGlobalScopes()->with(['client', 'job'])]);
        $search = trim((string) $request->query('q', ''));
        $shares = null;
        try {
            $shares = $box->members->isNotEmpty() ? $boxes->shares($box) : null;
        } catch (InvalidArgumentException) {
            $shares = null;
        }
        $charges = auth()->user()?->hasAnyRole(self::CHARGE_ROLES)
            ? Charge::query()->with(['chargeCode', 'job'])->where('source_type', 'container')->where('source_id', $box->id)->where('status', '!=', 'reversed')->whereNull('reversal_of_charge_id')->orderBy('job_id')->orderBy('id')->get()->groupBy('job_id')
            : collect();

        return view('warehouse::physical_containers.show', [
            'box' => $box,
            'shares' => $shares,
            'sharesByContainer' => $shares ? collect($shares['members'])->keyBy('container_id') : collect(),
            'candidates' => $box->isDevanned() ? collect() : $boxes->candidates($box, $search),
            'search' => $search,
            'charges' => $charges,
            'tasks' => WarehouseTask::query()->withoutGlobalScopes()->where('physical_container_id', $box->id)->orderByDesc('id')->get(),
        ]);
    }

    public function link(Request $request, PhysicalContainer $box, PhysicalContainerService $boxes): RedirectResponse
    {
        $data = $request->validate(['container_ids' => ['required', 'array', 'min:1'], 'container_ids.*' => ['integer', Rule::exists('containers', 'id')]], ['container_ids.required' => __('warehouse.physical_containers.errors.pick_rows'), 'container_ids.min' => __('warehouse.physical_containers.errors.pick_rows')]);
        try {
            $result = $boxes->link($box, array_map('intval', $data['container_ids']));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['link' => RuleViolation::display($e)]);
        }
        $status = __('warehouse.physical_containers.linked', ['count' => $result['linked']]);
        if ($result['warnings'] !== []) {
            $status .= ' '.implode(' ', $result['warnings']);
        }
        if ($box->fresh()->allocation_stale) {
            $status .= ' '.__('warehouse.physical_containers.stale_hint');
        }

        return redirect()->route('warehouse.physical_containers.show', $box)->with('status', $status);
    }

    public function unlink(PhysicalContainer $box, Container $container, PhysicalContainerService $boxes): RedirectResponse
    {
        try {
            $boxes->unlink($box, $container);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['link' => RuleViolation::display($e)]);
        }
        $status = __('warehouse.physical_containers.unlinked', ['no' => $container->container_no]);
        if ($box->fresh()->allocation_stale) {
            $status .= ' '.__('warehouse.physical_containers.stale_hint');
        }

        return back()->with('status', $status);
    }

    /** 登记到港 → physical_container.arrived (cartage / sideloader allocated over the members). */
    public function arrive(PhysicalContainer $box, PhysicalContainerService $boxes): RedirectResponse
    {
        try {
            $boxes->markArrived($box, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['arrive' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.physical_containers.arrived', ['no' => $box->container_no]));
    }

    /** 重算分摊 → the events already published for the box are re-emitted with activity_version + 1. */
    public function recompute(PhysicalContainer $box, PhysicalContainerService $boxes): RedirectResponse
    {
        try {
            $boxes->recompute($box, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['recompute' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.physical_containers.recomputed', ['no' => $box->container_no, 'version' => $box->fresh()->allocation_version]));
    }
}
