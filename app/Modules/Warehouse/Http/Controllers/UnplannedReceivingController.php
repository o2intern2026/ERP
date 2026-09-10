<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\WarehouseContext;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** 无预报收货 (CHANGE_REQUESTS #91): goods with no prior ASN received on one screen → unplanned ASN + completed 入库单. */
class UnplannedReceivingController extends Controller
{
    public function form(): View
    {
        return view('warehouse::receiving.unplanned', [
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'currentWarehouseId' => WarehouseContext::currentId(),
            'receivingLocations' => Location::query()->where('type', 'receiving')->where('active', true)->orderBy('full_code')->get()->groupBy('warehouse_id'),
            'inboundTypes' => Enums::INBOUND_TYPES,
            'unitTypes' => Enums::UNIT_TYPES,
            'rows' => array_values(array_filter((array) old('rows', []), 'is_array')) ?: [['unit_type' => 'pallet', 'unit_count' => 1]],
        ]);
    }

    public function store(Request $request, GoodsReceiptService $receipts): RedirectResponse
    {
        // Spare form rows (nothing typed) are dropped before validation; every remaining row must hold at least one carton.
        $request->merge(['rows' => array_values(array_filter((array) $request->input('rows', []), fn ($r) => is_array($r) && (filled($r['description'] ?? null) || filled($r['consignment_mark'] ?? null) || (int) ($r['received_cartons'] ?? 0) > 0 || (int) ($r['damaged_cartons'] ?? 0) > 0)))]);

        $validator = Validator::make($request->all(), [
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'inbound_type' => ['required', Rule::in(Enums::INBOUND_TYPES)],
            'delivery_reference' => ['nullable', 'string', 'max:100'],
            'receiving_location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('warehouse_id', $request->integer('warehouse_id'))->where('type', 'receiving')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.consignment_mark' => ['nullable', 'string', 'max:60'],
            'rows.*.description' => ['required', 'string', 'max:255'],
            'rows.*.received_cartons' => ['required', 'integer', 'min:0'],
            'rows.*.damaged_cartons' => ['nullable', 'integer', 'min:0'],
            'rows.*.unit_type' => ['required', Rule::in(Enums::UNIT_TYPES)],
            'rows.*.unit_count' => ['nullable', 'integer', 'min:1', 'max:500'],
            'rows.*.weight_kg' => ['nullable', 'numeric', 'min:0'],
            'rows.*.variance_reason' => ['nullable', 'string', 'max:255'],
        ], ['rows.required' => __('warehouse.receiving.unplanned.rows_required'), 'rows.min' => __('warehouse.receiving.unplanned.rows_required')]);
        $validator->after(function ($v) use ($request) {
            foreach ((array) $request->input('rows', []) as $i => $row) {
                if ((int) ($row['received_cartons'] ?? 0) + (int) ($row['damaged_cartons'] ?? 0) < 1) {
                    $v->errors()->add("rows.{$i}.received_cartons", __('warehouse.receiving.unplanned.row_min', ['row' => $i + 1]));
                }
            }
        });
        $data = $validator->validate();

        try {
            $receipt = $receipts->receiveUnplanned($data, (int) $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['rows' => RuleViolation::display($e)]);
        }

        return redirect()->route('warehouse.receipts.show', $receipt)->with('status', __('warehouse.receiving.unplanned.done', ['no' => $receipt->receipt_no]));
    }
}
