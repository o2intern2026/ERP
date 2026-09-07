<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\LabelService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** B11: PDF labels — units by id, all units of an ASN, or the locations of a warehouse. */
class LabelController extends Controller
{
    public function units(Request $request, LabelService $labels): Response
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);

        return $this->pdf($labels->unitLabels(StockUnit::query()->whereIn('id', $data['ids'])->orderBy('id')->get()), 'unit-labels.pdf');
    }

    public function asn(Asn $asn, LabelService $labels): Response
    {
        $units = StockUnit::query()->whereIn('asn_line_id', $asn->lines()->pluck('id'))->orderBy('id')->get();
        abort_if($units->isEmpty(), 404);

        return $this->pdf($labels->unitLabels($units), $asn->asn_no.'-labels.pdf');
    }

    public function locations(Request $request, LabelService $labels): Response
    {
        $data = $request->validate(['warehouse_id' => ['required', 'integer'], 'type' => ['nullable', 'string']]);
        $locations = Location::query()->where('warehouse_id', $data['warehouse_id'])->where('active', true)
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))->orderBy('full_code')->get();
        abort_if($locations->isEmpty(), 404);

        return $this->pdf($labels->locationLabels($locations), 'location-labels.pdf');
    }

    private function pdf(string $binary, string $filename): Response
    {
        return response($binary, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$filename.'"']);
    }
}
