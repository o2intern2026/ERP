<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnImport;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\AsnImportService;
use App\Modules\Warehouse\Services\AsnOrderGeneration;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** B2 / B2b: ASN list, creation (planned / unplanned, optional containers), goods lines by hand or Excel import. */
class AsnController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Enums::ASN_STATUSES)], 'client_id' => ['nullable', 'integer']]);

        return view('warehouse::asns.index', [
            'asns' => Asn::query()->with(['client', 'warehouse', 'job'])->withCount(['containers', 'lines'])
                ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
                ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
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
        ]);
    }

    public function store(Request $request, AsnService $asns): RedirectResponse
    {
        // The form renders empty container rows; only rows with a container number count.
        $request->merge(['containers' => array_values(array_filter((array) $request->input('containers', []), fn ($c) => filled($c['container_no'] ?? null)))]);

        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'inbound_type' => ['required', Rule::in(Enums::INBOUND_TYPES)],
            'expected_date' => ['nullable', 'date'],
            'job_id' => ['nullable', 'integer', Rule::exists('jobs', 'id')],
            'reference' => ['nullable', 'string', 'max:255'],
            'unplanned' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'containers' => ['nullable', 'array'],
            'containers.*.container_no' => ['required', 'string', 'max:20'],
            'containers.*.size' => ['required', Rule::in(Enums::CONTAINER_SIZES)],
            'containers.*.unpack_mode' => ['required', Rule::in(Enums::UNPACK_MODES)],
            'containers.*.gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['containers'] = $data['inbound_type'] === 'container'
            ? array_values(array_filter($data['containers'] ?? [], fn ($c) => ! empty($c['container_no'])))
            : [];

        $asn = $asns->create($data);

        return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.asns.created', ['asn_no' => $asn->asn_no]));
    }

    public function show(Asn $asn, GoodsReceiptService $receipts): View
    {
        $asn->load(['client', 'warehouse', 'job', 'containers', 'lines.stockUnits', 'lines.receiptLine', 'lines.container']);

        return view('warehouse::asns.show', [
            'asn' => $asn,
            'receipts' => $asn->goodsReceipts()->withCount('lines')->with('lines')->get(),
            'rollup' => $receipts->rollup($asn),
            'tasks' => $asn->hasMany(WarehouseTask::class)->orderByDesc('id')->get(),
            'imports' => AsnImport::query()->where('asn_id', $asn->id)->orderByDesc('id')->get(),
        ]);
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
        ]);

        $asns->addLines($asn, [$data]);

        return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.asns.line_added'));
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
