<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\LabelService;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * B11: PDF labels — units by id, all units of an ASN, or the locations of a warehouse.
 * Audit 2026-09-22 CRAWL-01 (CR #141): a PDF holds at most LabelService::BATCH_SIZE labels. A larger set without `?batch=` answers an HTML
 * page with one link per batch; `?batch=n` prints that slice. Location labels take zone / aisle-range / type filters.
 */
class LabelController extends Controller
{
    public function units(Request $request, LabelService $labels): Response|View
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'batch' => ['nullable', 'integer', 'min:1']]);
        $units = StockUnit::query()->whereIn('id', $data['ids'])->orderBy('id')->get();
        abort_if($units->isEmpty(), 404);

        return $this->batched($units, $data['batch'] ?? null, __('warehouse.labels.units'), ['ids' => $data['ids']], 'warehouse.labels.units',
            fn (Collection $slice) => $this->pdf($labels->unitLabels($slice), 'unit-labels.pdf'));
    }

    public function asn(Request $request, Asn $asn, LabelService $labels): Response|View
    {
        $data = $request->validate(['batch' => ['nullable', 'integer', 'min:1']]);
        $units = StockUnit::query()->whereIn('asn_line_id', $asn->lines()->pluck('id'))->orderBy('id')->get();
        abort_if($units->isEmpty(), 404);

        return $this->batched($units, $data['batch'] ?? null, $asn->asn_no.' · '.__('warehouse.labels.units'), ['asn' => $asn->id], 'warehouse.labels.asn',
            fn (Collection $slice, int $batch) => $this->pdf($labels->unitLabels($slice), $asn->asn_no.'-labels-'.$batch.'.pdf'));
    }

    public function locations(Request $request, LabelService $labels): Response|View
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'type' => ['nullable', Rule::in(Enums::LOCATION_TYPES)],
            'zone' => ['nullable', 'string', 'max:10'], 'aisle_from' => ['nullable', 'string', 'max:10'], 'aisle_to' => ['nullable', 'string', 'max:10'],
            'batch' => ['nullable', 'integer', 'min:1'],
        ]);
        $locations = Location::query()->where('warehouse_id', $data['warehouse_id'])->where('active', true)
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when(filled($data['zone'] ?? null), fn ($q) => $q->where('zone', strtoupper(trim((string) $data['zone']))))
            ->orderBy('full_code')->get()
            ->filter(fn (Location $l) => Location::codeBetween($l->aisle, $data['aisle_from'] ?? null, $data['aisle_to'] ?? null))->values();
        abort_if($locations->isEmpty(), 404);

        $warehouse = Warehouse::query()->find($data['warehouse_id']);
        $filters = array_filter(['warehouse_id' => $data['warehouse_id'], 'type' => $data['type'] ?? null, 'zone' => $data['zone'] ?? null, 'aisle_from' => $data['aisle_from'] ?? null, 'aisle_to' => $data['aisle_to'] ?? null], fn ($v) => filled($v));

        return $this->batched($locations, $data['batch'] ?? null, ($warehouse?->code ?? '').' · '.__('warehouse.labels.locations'), $filters, 'warehouse.labels.locations',
            fn (Collection $slice, int $batch) => $this->pdf($labels->locationLabels($slice), 'location-labels-'.$batch.'.pdf'));
    }

    /**
     * One PDF when the set fits a batch or a batch was asked for; otherwise the batch-links page. $render(slice, batch) builds the PDF.
     *
     * @param  array<string, mixed>  $query  the route parameters / query the batch links repeat
     */
    private function batched(Collection $items, ?int $batch, string $title, array $query, string $route, callable $render): Response|View
    {
        $batches = LabelService::batches($items->count());
        if ($batch === null && $batches === 1) {
            $batch = 1;
        }
        if ($batch !== null) {
            abort_if($batch > $batches, 404);

            return $render(LabelService::batch($items, $batch), $batch);
        }

        return view('warehouse::labels.batches', [
            'title' => $title,
            'total' => $items->count(),
            'batches' => $batches,
            'size' => LabelService::BATCH_SIZE,
            'links' => collect(range(1, $batches))->map(fn (int $n) => ['n' => $n, 'from' => ($n - 1) * LabelService::BATCH_SIZE + 1, 'to' => min($n * LabelService::BATCH_SIZE, $items->count()), 'url' => route($route, $query + ['batch' => $n])]),
        ]);
    }

    private function pdf(string $binary, string $filename): Response
    {
        return response($binary, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$filename.'"']);
    }
}
