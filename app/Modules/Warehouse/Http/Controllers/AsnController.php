<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnImport;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\PhysicalContainer;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\AsnImportService;
use App\Modules\Warehouse\Services\AsnOrderGeneration;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Auth\RequiredRoles;
use App\Support\Contracts\OrderService;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** B2 / B2b: ASN list, creation (planned / unplanned, optional containers), goods lines by hand, Excel import or from the client's orders (#119). */
class AsnController extends Controller
{
    /** Who may import the client's pending orders as goods lines on the ASN page (CHANGE_REQUESTS #119). */
    private const IMPORT_ORDERS_ROLES = ['admin', 'customer_service', 'warehouse_supervisor'];

    /** Who may request 我方上门提货 or switch back to 客户自送 (CHANGE_REQUESTS #124) — mirrors routes.php. */
    private const COLLECTION_ROLES = ['admin', 'customer_service', 'warehouse_supervisor'];

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Enums::ASN_STATUSES)], 'client_id' => ['nullable', 'integer'], 'pending' => ['nullable', 'boolean']]);

        return view('warehouse::asns.index', [
            'asns' => Asn::query()->with(['client', 'warehouse', 'job'])->withCount(['containers', 'lines'])
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
                ->when($filters['pending'] ?? null, fn ($q) => $q->where('created_by_type', 'client')->whereNull('client_confirmed_at')) // 待客服确认 (CHANGE_REQUESTS #116)
                ->when(WarehouseContext::currentId(), fn ($q, $v) => $q->where('warehouse_id', $v))
                ->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => Enums::ASN_STATUSES,
        ]);
    }

    public function create(): View
    {
        return view('warehouse::asns.create', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'jobs' => Job::query()->whereIn('operational_status', ['open', 'receiving'])->orderByDesc('id')->limit(200)->get(['id', 'job_no', 'client_id']),
            'canRequestCollection' => (bool) auth()->user()?->hasAnyRole(self::COLLECTION_ROLES),
            'currentWarehouseId' => WarehouseContext::currentId(), // the session warehouse is the default, as on the walk-in form (audit 2026-09-22 INBOUND-13)
        ]);
    }

    public function store(Request $request, AsnService $asns): RedirectResponse
    {
        // The form renders empty container rows; only rows with a container number count.
        $request->merge(['containers' => array_values(array_filter((array) $request->input('containers', []), fn ($c) => filled($c['container_no'] ?? null)))]);
        $this->pruneCollectionPackages($request);
        $weCollect = $request->input('inbound_transport') === 'we_collect';

        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'inbound_type' => ['required', Rule::in(Enums::INBOUND_TYPES)],
            'inbound_transport' => ['nullable', Rule::in(Enums::ASN_INBOUND_TRANSPORTS)],
            'expected_date' => ['nullable', 'date'],
            // Audit 2026-09-22 INBOUND-13 (CR #141): the Job must belong to the chosen client — an ASN of client A could land on client B's Job.
            'job_id' => ['nullable', 'integer', Rule::exists('jobs', 'id')->where('client_id', $request->integer('client_id'))],
            'reference' => ['nullable', 'string', 'max:255'],
            'unplanned' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'containers' => ['nullable', 'array'],
            'containers.*.container_no' => ['required', 'string', 'max:20'],
            'containers.*.size' => ['required', Rule::in(Enums::CONTAINER_SIZES)],
            'containers.*.unpack_mode' => ['required', Rule::in(Enums::UNPACK_MODES)],
            'containers.*.gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ] + $this->collectionRules($weCollect, packagesRequired: true), ['job_id.exists' => __('warehouse.asns.job_not_of_client')] + $this->collectionMessages());

        $data['containers'] = $data['inbound_type'] === 'container'
            ? array_values(array_filter($data['containers'] ?? [], fn ($c) => ! empty($c['container_no'])))
            : [];

        if ($weCollect) {
            RequiredRoles::requireAny(self::COLLECTION_ROLES);
        }

        try {
            $asn = DB::transaction(function () use ($asns, $data, $weCollect): Asn {
                $asn = $asns->create(Arr::except($data, ['inbound_transport', 'collection', 'collection_ready_date', 'collection_notes', 'collection_packages']));
                if ($weCollect) {
                    $asn = $asns->setCollection($asn, $this->collectionPayload($data), auth()->id());
                }

                return $asn;
            });
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['collection' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.asns.created', ['asn_no' => $asn->asn_no]));
    }

    /** 我方上门提货 (CHANGE_REQUESTS #124): request or re-request the collection — Transport quotes it; the plan is confirmed on the shipment page. */
    public function updateCollection(Request $request, Asn $asn, AsnService $asns): RedirectResponse
    {
        RequiredRoles::requireAny(self::COLLECTION_ROLES);
        $this->pruneCollectionPackages($request);
        $data = $request->validate($this->collectionRules(true, packagesRequired: false), $this->collectionMessages());

        try {
            $asns->setCollection($asn, $this->collectionPayload($data), auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['collection' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.asns.show', $asn)->withFragment('collection')->with('status', __('warehouse.asns.collection.requested', ['no' => $asn->asn_no]));
    }

    /** 改为客户自送: only while Transport has not booked the collection. */
    public function destroyCollection(Asn $asn, AsnService $asns): RedirectResponse
    {
        RequiredRoles::requireAny(self::COLLECTION_ROLES);

        try {
            $asns->clearCollection($asn, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['collection' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.asns.show', $asn)->withFragment('collection')->with('status', __('warehouse.asns.collection.cancelled', ['no' => $asn->asn_no]));
    }

    /** The form renders empty package rows; only rows with a quantity count. */
    private function pruneCollectionPackages(Request $request): void
    {
        $request->merge(['collection_packages' => array_values(array_filter((array) $request->input('collection_packages', []), fn ($p) => is_array($p) && filled($p['qty'] ?? null)))]);
    }

    /**
     * Validation of the 到仓方式 fields (shared by the create form and 修改). `$required` = the request is a collection (fields
     * mandatory); `$packagesRequired` = no goods lines exist yet (the create form), so the packages must be declared.
     *
     * @return array<string, list<mixed>>
     */
    private function collectionRules(bool $required, bool $packagesRequired): array
    {
        $when = $required ? 'required' : 'nullable';

        return [
            'collection' => [$when, 'array'],
            'collection.name' => [$when, 'string', 'max:255'],
            'collection.phone' => ['nullable', 'string', 'max:40'],
            'collection.address' => [$when, 'string', 'max:255'],
            'collection.suburb' => [$when, 'string', 'max:100'],
            'collection.state' => [$when, Rule::in(Enums::STATES)],
            'collection.postcode' => [$when, 'string', 'max:10'],
            'collection.type' => ['nullable', Rule::in(Enums::ADDRESS_TYPES)],
            'collection_ready_date' => [$when, 'date', 'after_or_equal:today'],
            'collection_notes' => ['nullable', 'string', 'max:2000'],
            'collection_packages' => [$required && $packagesRequired ? 'required' : 'nullable', 'array'],
            'collection_packages.*.package_type' => ['required', Rule::in(Enums::COLLECTION_PACKAGE_TYPES)],
            'collection_packages.*.qty' => ['required', 'integer', 'min:1'],
            'collection_packages.*.weight_kg' => ['required', 'numeric', 'min:0.001'],
            'collection_packages.*.length_mm' => ['required', 'integer', 'min:1'],
            'collection_packages.*.width_mm' => ['required', 'integer', 'min:1'],
            'collection_packages.*.height_mm' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    private function collectionMessages(): array
    {
        return [
            'collection.*.required' => __('warehouse.asns.collection.errors.address_incomplete'),
            'collection.required' => __('warehouse.asns.collection.errors.address_incomplete'),
            'collection_ready_date.required' => __('warehouse.asns.collection.errors.ready_date_required'),
            'collection_ready_date.after_or_equal' => __('warehouse.asns.collection.errors.ready_date_past'),
            'collection_packages.required' => __('warehouse.asns.collection.errors.no_packages'),
            'collection_packages.*.*.required' => __('warehouse.asns.collection.errors.package_incomplete'),
            'collection_packages.*.*.min' => __('warehouse.asns.collection.errors.package_incomplete'),
            'collection_packages.*.*.integer' => __('warehouse.asns.collection.errors.package_incomplete'),
            'collection_packages.*.*.numeric' => __('warehouse.asns.collection.errors.package_incomplete'),
            'collection_packages.*.*.in' => __('warehouse.asns.collection.errors.package_incomplete'),
        ];
    }

    /** @return array{address:array<string, mixed>, ready_date:string, packages:list<array<string, mixed>>, notes:?string} */
    private function collectionPayload(array $data): array
    {
        return [
            'address' => $data['collection'] ?? [],
            'ready_date' => (string) ($data['collection_ready_date'] ?? ''),
            'packages' => $data['collection_packages'] ?? [],
            'notes' => $data['collection_notes'] ?? null,
        ];
    }

    public function show(Asn $asn, GoodsReceiptService $receipts): View
    {
        $asn->load(['client', 'warehouse', 'job', 'containers.physicalContainer', 'lines.stockUnits', 'lines.receiptLine', 'lines.container', 'createdBy', 'clientConfirmedBy', 'collectionRequestedBy']);
        $canImportOrders = in_array($asn->status, ['booked', 'arrived', 'receiving'], true) && auth()->user()?->hasAnyRole(self::IMPORT_ORDERS_ROLES);
        $orderCandidates = $canImportOrders ? app(OrderService::class)->awaitingAsn((int) $asn->client_id) : [];
        $orderRefs = $this->orderRefs($asn);
        // 物理柜 (CHANGE_REQUESTS #122): an unlinked row's 关联物理柜 shortcut goes to the open box with the same number, else to the create form pre-filled.
        $unlinkedNos = $asn->containers->filter(fn ($c) => ! $c->isLinked())->pluck('container_no')->all();
        $openBoxes = $unlinkedNos === [] ? collect() : PhysicalContainer::query()->whereIn('container_no', $unlinkedNos)->where('warehouse_id', $asn->warehouse_id)->whereIn('status', ['expected', 'arrived'])->get()->keyBy('container_no');
        $boxIds = $asn->containers->pluck('physical_container_id')->filter()->unique()->values()->all();

        return view('warehouse::asns.show', [
            'asn' => $asn,
            'receipts' => $asn->goodsReceipts()->withCount('lines')->with('lines')->get(),
            'rollup' => $receipts->rollup($asn),
            'tasks' => $asn->hasMany(WarehouseTask::class)->orderByDesc('id')->get(),
            'boxTasks' => $boxIds === [] ? collect() : WarehouseTask::query()->withoutGlobalScopes()->with('physicalContainer')->whereIn('physical_container_id', $boxIds)->orderByDesc('id')->get(),
            'openBoxes' => $openBoxes,
            'allLinked' => $asn->containers->isNotEmpty() && $asn->containers->every(fn ($c) => $c->isLinked()),
            'imports' => AsnImport::query()->where('asn_id', $asn->id)->orderByDesc('id')->get(),
            'orderRefs' => $orderRefs,
            'fromOrders' => array_filter($orderRefs, fn (array $ref) => $ref['from_order']) !== [],
            'canImportOrders' => $canImportOrders,
            'orderCandidates' => $orderCandidates,
            'blockedJobs' => $this->blockedJobs($asn, $orderCandidates),
            // 到仓方式 (CHANGE_REQUESTS #124): request / edit / 改为客户自送 while the ASN is still booked and Transport has not booked the collection.
            'canManageCollection' => $asn->status === 'booked' && ! $asn->collectionLocked() && auth()->user()?->hasAnyRole(self::COLLECTION_ROLES),
        ]);
    }

    /**
     * 来源订单 per goods line: asn_line_id → ['order_id', 'order_no', 'from_order'] for the lines linked to an order. Read-only look
     * at order_lines / orders, the same precedent as AsnOrderGeneration. `from_order` tells the direction: the order line existed
     * before the ASN line (从订单生成预报单 / 从订单导入货物行), as opposed to an order generated FROM the ASN (从预报单生成派送订单),
     * which also writes order_line_id — only the former earns the 由订单生成 badge.
     *
     * @return array<int, array{order_id:int, order_no:string, from_order:bool}>
     */
    private function orderRefs(Asn $asn): array
    {
        $lineIds = $asn->lines->whereNotNull('order_line_id')->pluck('order_line_id')->all();
        if ($lineIds === []) {
            return [];
        }

        $rows = DB::table('order_lines')->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('order_lines.id', $lineIds)
            ->get(['order_lines.id as order_line_id', 'order_lines.created_at as line_created_at', 'orders.id as order_id', 'orders.order_no'])
            ->keyBy('order_line_id');

        $refs = [];
        foreach ($asn->lines as $line) {
            if ($line->order_line_id !== null && isset($rows[$line->order_line_id])) {
                $row = $rows[$line->order_line_id];
                $refs[$line->id] = [
                    'order_id' => (int) $row->order_id,
                    'order_no' => (string) $row->order_no,
                    'from_order' => $row->line_created_at !== null && $line->created_at !== null && Carbon::parse($row->line_created_at)->lte($line->created_at),
                ];
            }
        }

        return $refs;
    }

    /**
     * Candidate orders whose Job already carries another ASN cannot be merged (OrderService refuses with job_has_asn) — their
     * rows render disabled with a hint instead of failing the whole import after submit.
     *
     * @param  list<array{job_id:int}>  $candidates
     * @return list<int>
     */
    private function blockedJobs(Asn $asn, array $candidates): array
    {
        $jobIds = array_values(array_unique(array_column($candidates, 'job_id')));
        if ($jobIds === []) {
            return [];
        }

        return Asn::query()->withoutGlobalScopes()->whereIn('job_id', $jobIds)->where('job_id', '!=', $asn->job_id)
            ->distinct()->pluck('job_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** 从订单导入货物行 (CHANGE_REQUESTS #119): the client's pending orders become goods lines of this ASN, consignee and cartons from the order. */
    public function importOrders(Request $request, Asn $asn, OrderService $orders): RedirectResponse
    {
        RequiredRoles::requireAny(self::IMPORT_ORDERS_ROLES);
        $containers = $asn->containers()->orderBy('id')->pluck('container_no')->map(fn ($no) => (string) $no)->all();
        $pickContainer = __('warehouse.asns.errors.import_orders_pick_container', ['no' => $asn->asn_no, 'containers' => $containers === [] ? '—' : implode(', ', $containers)]);
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', Rule::exists('orders', 'id')],
            'container_no' => ['nullable', 'string', 'max:20', Rule::requiredIf(count($containers) > 1), Rule::in($containers)],
        ], [
            'order_ids.required' => __('orders.inbound.errors.none_selected'),
            'order_ids.min' => __('orders.inbound.errors.none_selected'),
            'container_no.required' => $pickContainer,
            'container_no.in' => $pickContainer,
        ]);

        try {
            $result = $orders->attachOrdersToAsn($asn->id, array_values(array_map('intval', $data['order_ids'])), auth()->id(), $data['container_no'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['import_orders' => RuleViolation::display($e)]);
        }

        $status = __('warehouse.asns.orders_imported', ['count' => $result['orders'], 'lines' => $result['lines']]);
        if ($result['merged'] !== []) {
            $status .= ' '.__('orders.inbound.merged_note', [
                'job_no' => $result['job_no'],
                'orders' => implode(', ', $result['merged']),
                'jobs' => $result['cancelled'] === [] ? '—' : implode(', ', $result['cancelled']),
            ]);
        }

        return redirect()->route('warehouse.asns.show', $asn)->with('status', $status);
    }

    public function storeLine(Request $request, Asn $asn, AsnService $asns): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'expected_cartons' => ['required', 'integer', 'min:0'],
            'consignment_mark' => ['nullable', 'string', 'max:60'],
            'container_no' => ['nullable', 'string', 'max:20'],
            'package_type' => ['nullable', 'string', 'max:40'],
            'fba_reference' => ['nullable', 'string', 'max:60'],
            'deliver_to_name' => ['nullable', 'string', 'max:255'],
            'deliver_to_phone' => ['nullable', 'string', 'max:40'],
            'deliver_to_address' => ['nullable', 'string', 'max:255'],
            'deliver_to_suburb' => ['nullable', 'string', 'max:100'],
            'deliver_to_state' => ['nullable', Rule::in(Enums::STATES)],
            'deliver_to_postcode' => ['nullable', 'string', 'max:10'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_mm' => ['nullable', 'integer', 'min:0'],
            'width_mm' => ['nullable', 'integer', 'min:0'],
            'height_mm' => ['nullable', 'integer', 'min:0'],
            'cbm' => ['nullable', 'numeric', 'min:0'],
            'storage_tier' => ['nullable', Rule::in(Enums::STORAGE_TIERS)], // CHANGE_REQUESTS #126
        ]);
        if (filled($data['storage_tier'] ?? null)) {
            $data['storage_tier_source'] = 'staff';
        }

        $asns->addLines($asn, [$data]);

        return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.asns.line_added'));
    }

    /** 存储等级 of one goods line (CHANGE_REQUESTS #126): staff only — the line's existing stock units follow. */
    public function updateStorageTier(Request $request, Asn $asn, AsnLine $line, AsnService $asns): RedirectResponse
    {
        abort_unless($line->asn_id === $asn->id, 404);
        $data = $request->validate(['storage_tier' => ['required', Rule::in(Enums::STORAGE_TIERS)]]);

        $asns->setLineStorageTier($line, $data['storage_tier']);

        return redirect()->to(route('warehouse.asns.show', $asn).'#lines')->with('status', __('warehouse.line_tier.updated', ['line' => $line->id, 'tier' => __('warehouse.storage_tiers.'.$data['storage_tier'])]));
    }

    /** 编辑收件信息 (CHANGE_REQUESTS #115): the consignee fields 从预报单生成派送订单 needs, one goods line at a time (optionally copied to its mark). */
    public function editDelivery(Asn $asn, AsnLine $line): View
    {
        abort_unless($line->asn_id === $asn->id, 404);

        return view('warehouse::asns.delivery', [
            'asn' => $asn->load(['client', 'warehouse']),
            'line' => $line,
            'siblings' => $this->siblingsUnderMark($line),
        ]);
    }

    public function updateDelivery(Request $request, Asn $asn, AsnLine $line, AsnService $asns): RedirectResponse
    {
        abort_unless($line->asn_id === $asn->id, 404);
        $data = $request->validate([
            'consignment_mark' => ['nullable', 'string', 'max:60'],
            'deliver_to_name' => ['required', 'string', 'max:255'],
            'deliver_to_phone' => ['nullable', 'string', 'max:40'],
            'deliver_to_address' => ['required', 'string', 'max:255'],
            'deliver_to_suburb' => ['required', 'string', 'max:100'],
            'deliver_to_state' => ['required', Rule::in(Enums::STATES)],
            'deliver_to_postcode' => ['required', 'string', 'max:10'],
            'fba_reference' => ['nullable', 'string', 'max:60'],
            'apply_to_mark' => ['nullable', 'boolean'],
        ]);

        try {
            $count = $asns->updateLineDelivery($line, Arr::except($data, ['apply_to_mark']), (bool) ($data['apply_to_mark'] ?? false));
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['delivery' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.asns.delivery_saved', ['count' => $count]));
    }

    /** Other goods lines of the ASN under the same mark that are not on an order yet — the ones "同时应用到同一唛头" would update. */
    private function siblingsUnderMark(AsnLine $line): int
    {
        if (trim((string) $line->consignment_mark) === '') {
            return 0;
        }

        return AsnLine::query()->where('asn_id', $line->asn_id)->whereKeyNot($line->id)->where('consignment_mark', $line->consignment_mark)->whereNull('order_line_id')->count();
    }

    public function import(Request $request, Asn $asn, AsnImportService $imports): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'container_no' => ['nullable', 'string', 'max:20'],
        ]);

        $import = $imports->import($asn, $data['file'], $data['container_no'] ?? null);

        return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.asns.imported', [
            'rows' => $import->row_count, 'errors' => $import->error_count, 'warnings' => count($import->warnings ?? []),
        ]));
    }

    public function arrive(Asn $asn, AsnService $asns): RedirectResponse
    {
        $asns->markArrived($asn);

        return back()->with('status', __('warehouse.asns.arrived'));
    }

    public function confirmUnplanned(Asn $asn, AsnService $asns): RedirectResponse
    {
        $asns->confirmUnplanned($asn);

        return back()->with('status', __('warehouse.asns.unplanned_confirmed'));
    }

    /** B2c: one click → OMS OrderService::createFromAsn; asn_lines.order_line_id written back; idempotent (§4.7 #17 #18). */
    /** 确认客户预报 (CHANGE_REQUESTS #116): customer service signs off a portal / API submission; the badge goes here and in the portal. */
    public function confirmClient(Asn $asn, AsnService $asns): RedirectResponse
    {
        try {
            $asns->confirmClientSubmission($asn, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['confirm_client' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.asns.client_confirmed', ['no' => $asn->asn_no]));
    }

    public function generateOrders(Asn $asn, AsnOrderGeneration $generation): RedirectResponse
    {
        try {
            $result = $generation->generate($asn);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['generate' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('warehouse.asns.orders_generated', ['count' => count($result['orders']), 'lines' => $result['linked_lines'], 'blocked' => count($result['blocked'])]))
            ->with('blocked', $result['blocked']);
    }
}
