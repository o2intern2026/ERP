<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\TaskService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * B2 receiving: the 待收货 worklist (every ASN line still awaiting receipt) and the per-line form — received / damaged / reason,
 * measured units, pallet source; LCL unloaded pallets → receiving task. Each receipt joins the ASN's open 入库单 batch.
 */
class ReceivingController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['client_id' => ['nullable', 'integer'], 'warehouse_id' => ['nullable', 'integer']]);
        // First load: default to the session warehouse. A submitted filter wins, including an explicit 全部 (empty value → null = all).
        $warehouseId = $request->has('warehouse_id') ? ($filters['warehouse_id'] ?? null) : WarehouseContext::currentId();

        return view('warehouse::receiving.index', [
            'lines' => AsnLine::query()->with(['asn.client', 'asn.warehouse', 'container'])
                ->whereHas('asn', fn ($q) => $q->whereIn('status', ['booked', 'arrived', 'receiving'])
                    ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
                    ->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v)))
                ->whereDoesntHave('stockUnits')->whereDoesntHave('receiptLine')
                ->orderBy('asn_id')->orderBy('id')->paginate(50)->withQueryString(),
            'filters' => ['warehouse_id' => $warehouseId] + $filters, // the dropdown shows the warehouse actually applied
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code']),
        ]);
    }

    public function form(Asn $asn, AsnLine $line, GoodsReceiptService $receipts): View
    {
        abort_unless($line->asn_id === $asn->id, 404);

        return view('warehouse::receiving.form', [
            'asn' => $asn->load('client', 'warehouse'),
            'line' => $line,
            'receipt' => $receipts->nextReceiptNo($asn),
            'receivingLocations' => Location::query()->where('warehouse_id', $asn->warehouse_id)->where('type', 'receiving')->where('active', true)->orderBy('full_code')->get(),
            'palletSources' => Enums::PALLET_SOURCES,
            'palletClasses' => Enums::PALLET_CLASSES,
        ]);
    }

    public function store(Request $request, Asn $asn, AsnLine $line, ReceivingService $receiving, TaskService $tasks): RedirectResponse
    {
        abort_unless($line->asn_id === $asn->id, 404);
        // Spare unit rows the operator never touched (no 箱数) are dropped BEFORE validation — testers hit "units.1.carton_qty is required" on hidden rows (2026-09-10).
        $request->merge(['units' => array_values(array_filter((array) $request->input('units', []), fn ($u) => is_array($u) && filled($u['carton_qty'] ?? null)))]);

        $data = $request->validate([
            'receiving_location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('warehouse_id', $asn->warehouse_id)->where('type', 'receiving')],
            'received_cartons' => ['required', 'integer', 'min:0'],
            'damaged_cartons' => ['nullable', 'integer', 'min:0'],
            'variance_reason' => ['nullable', 'string', 'max:255'],
            'unloaded_pallets' => ['nullable', 'integer', 'min:0'],
            // Audit 2026-09-10: required_if has no comparison operators — the old rule fired on 0 and never on a positive count.
            'units' => ['array', function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                if ((int) $request->input('received_cartons') > 0 && array_filter((array) $value, fn ($u) => is_array($u) && (int) ($u['carton_qty'] ?? 0) > 0) === []) {
                    $fail(__('warehouse.receiving.units_required'));
                }
            }],
            'units.*.unit_type' => ['required', Rule::in(Enums::UNIT_TYPES)],
            'units.*.carton_qty' => ['required', 'integer', 'min:1'],
            'units.*.length_mm' => ['nullable', 'integer', 'min:1'],
            'units.*.width_mm' => ['nullable', 'integer', 'min:1'],
            'units.*.height_mm' => ['nullable', 'integer', 'min:1'],
            'units.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'units.*.pallet_source' => ['nullable', Rule::in(Enums::PALLET_SOURCES)],
            'units.*.pallet_class' => ['nullable', Rule::in(Enums::PALLET_CLASSES)],
            'units.*.pallet_class_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $units = array_values(array_filter($data['units'] ?? [], fn ($u) => (int) ($u['carton_qty'] ?? 0) > 0));
        $location = Location::query()->findOrFail($data['receiving_location_id']);

        $receiving->receiveLine($line, ['received_cartons' => (int) $data['received_cartons'], 'damaged_cartons' => (int) ($data['damaged_cartons'] ?? 0), 'variance_reason' => $data['variance_reason'] ?? null, 'units' => $units], $location, $request->user()?->id);
        $receiptNo = $line->refresh()->receiptLine?->receipt?->receipt_no ?? '—';

        // Truck (LCL) receiving records unloaded pallets on a receiving task → WH-UNLOAD-PLT (contracts/charge-codes.md #9).
        if ($asn->inbound_type === 'loose_truck' && (int) ($data['unloaded_pallets'] ?? 0) > 0) {
            $task = $tasks->create('receiving', ['job_id' => $asn->job_id, 'client_id' => $asn->client_id, 'warehouse_id' => $asn->warehouse_id, 'source_type' => 'asn', 'source_id' => $asn->id, 'asn_id' => $asn->id]);
            $tasks->complete($task, ['billable_qty' => (int) $data['unloaded_pallets'], 'billable_uom' => 'pallet'], $asn->asn_no);
        }

        return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.receiving.received', ['line' => $line->id, 'receipt' => $receiptNo]));
    }

    /** 手动填写入库单: every not-yet-received line of the ASN on one screen (tester feedback #4, 2026-09-10). */
    public function bulkForm(Asn $asn, GoodsReceiptService $receipts): View
    {
        $asn->load(['client', 'warehouse', 'lines.container', 'lines.stockUnits', 'lines.receiptLine']);

        return view('warehouse::receiving.bulk', [
            'asn' => $asn,
            'lines' => $asn->lines->reject(fn (AsnLine $l) => $l->isReceived())->values(),
            'receipt' => $receipts->nextReceiptNo($asn),
            'receivingLocations' => Location::query()->where('warehouse_id', $asn->warehouse_id)->where('type', 'receiving')->where('active', true)->orderBy('full_code')->get(),
            'unitTypes' => Enums::UNIT_TYPES,
            'defaultUnitType' => $asn->inbound_type === 'loose_truck' ? 'pallet' : 'carton',
            'receivable' => in_array($asn->status, ['booked', 'arrived', 'receiving'], true),
        ]);
    }

    public function bulkStore(Request $request, Asn $asn, GoodsReceiptService $receipts): RedirectResponse
    {
        abort_unless(in_array($asn->status, ['booked', 'arrived', 'receiving'], true), 409, __('warehouse.receiving.bulk.not_receivable'));
        // Only ticked rows count; unticked rows are dropped before validation so an untouched line never blocks the others.
        $request->merge(['rows' => array_values(array_filter((array) $request->input('rows', []), fn ($r) => is_array($r) && ($r['include'] ?? null)))]);

        $validator = Validator::make($request->all(), [
            'receiving_location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('warehouse_id', $asn->warehouse_id)->where('type', 'receiving')],
            'delivery_reference' => ['nullable', 'string', 'max:100'],
            'complete' => ['nullable', 'boolean'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.asn_line_id' => ['required', 'integer', Rule::exists('asn_lines', 'id')->where('asn_id', $asn->id)],
            'rows.*.received_cartons' => ['required', 'integer', 'min:0'],
            'rows.*.damaged_cartons' => ['nullable', 'integer', 'min:0'],
            'rows.*.unit_type' => ['required', Rule::in(Enums::UNIT_TYPES)],
            'rows.*.unit_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'rows.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'rows.*.variance_reason' => ['nullable', 'string', 'max:255'],
        ], ['rows.required' => __('warehouse.receiving.bulk.rows_required'), 'rows.min' => __('warehouse.receiving.bulk.rows_required')]);
        $validator->after(function ($v) use ($request, $asn) {
            $expected = $asn->lines()->pluck('expected_cartons', 'id');
            foreach ((array) $request->input('rows', []) as $i => $row) {
                $received = (int) ($row['received_cartons'] ?? 0);
                $damaged = (int) ($row['damaged_cartons'] ?? 0);
                $planned = (int) ($expected[(int) ($row['asn_line_id'] ?? 0)] ?? 0);
                if (($received + $damaged !== $planned || $damaged > 0) && blank($row['variance_reason'] ?? null)) {
                    $v->errors()->add("rows.{$i}.variance_reason", __('warehouse.receiving.bulk.reason_required', ['line' => $row['asn_line_id'] ?? '?']));
                }
            }
        });
        $data = $validator->validate();

        try {
            $receipt = $receipts->receiveLines($asn, $data['rows'], Location::query()->findOrFail($data['receiving_location_id']), (int) $request->user()->id, (bool) ($data['complete'] ?? false), $data['delivery_reference'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['rows' => $e->getMessage()]);
        }

        $count = count($data['rows']);
        if ($receipt->isOpen()) {
            return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.receiving.bulk.done', ['count' => $count, 'no' => $receipt->receipt_no]));
        }

        return redirect()->route('warehouse.receipts.show', $receipt)->with('status', __('warehouse.receiving.bulk.done_completed', ['count' => $count, 'no' => $receipt->receipt_no]));
    }
}
