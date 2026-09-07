<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\TaskService;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** B2 receiving form: per line received / damaged / reason, measured units, pallet source; LCL unloaded pallets → receiving task. */
class ReceivingController extends Controller
{
    public function form(Asn $asn, AsnLine $line): View
    {
        abort_unless($line->asn_id === $asn->id, 404);

        return view('warehouse::receiving.form', [
            'asn' => $asn->load('client', 'warehouse'),
            'line' => $line,
            'receivingLocations' => Location::query()->where('warehouse_id', $asn->warehouse_id)->where('type', 'receiving')->where('active', true)->orderBy('full_code')->get(),
            'palletSources' => Enums::PALLET_SOURCES,
            'palletClasses' => Enums::PALLET_CLASSES,
        ]);
    }

    public function store(Request $request, Asn $asn, AsnLine $line, ReceivingService $receiving, TaskService $tasks): RedirectResponse
    {
        abort_unless($line->asn_id === $asn->id, 404);

        $data = $request->validate([
            'receiving_location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('warehouse_id', $asn->warehouse_id)->where('type', 'receiving')],
            'received_cartons' => ['required', 'integer', 'min:0'],
            'damaged_cartons' => ['nullable', 'integer', 'min:0'],
            'variance_reason' => ['nullable', 'string', 'max:255'],
            'unloaded_pallets' => ['nullable', 'integer', 'min:0'],
            'units' => ['required_if:received_cartons,>,0', 'array'],
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

        $receiving->receiveLine($line, ['received_cartons' => (int) $data['received_cartons'], 'damaged_cartons' => (int) ($data['damaged_cartons'] ?? 0), 'variance_reason' => $data['variance_reason'] ?? null, 'units' => $units], $location);

        // Truck (LCL) receiving records unloaded pallets on a receiving task → WH-UNLOAD-PLT (contracts/charge-codes.md #9).
        if ($asn->inbound_type === 'loose_truck' && (int) ($data['unloaded_pallets'] ?? 0) > 0) {
            $task = $tasks->create('receiving', ['job_id' => $asn->job_id, 'client_id' => $asn->client_id, 'warehouse_id' => $asn->warehouse_id, 'source_type' => 'asn', 'source_id' => $asn->id, 'asn_id' => $asn->id]);
            $tasks->complete($task, ['billable_qty' => (int) $data['unloaded_pallets'], 'billable_uom' => 'pallet'], $asn->asn_no);
        }

        return redirect()->route('warehouse.asns.show', $asn)->with('status', __('warehouse.receiving.received', ['line' => $line->id]));
    }
}
