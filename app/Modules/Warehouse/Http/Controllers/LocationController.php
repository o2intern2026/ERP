<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Warehouse configuration: locations (four-level code) per warehouse; rack level + storage tier (CHANGE_REQUESTS #126). */
class LocationController extends Controller
{
    public function index(): View
    {
        return view('warehouse::locations.index', [
            'warehouses' => Warehouse::query()->with(['locations' => fn ($q) => $q->orderBy('full_code')])->orderBy('code')->get(),
            'types' => Enums::LOCATION_TYPES,
            'tiers' => Enums::STORAGE_TIERS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'zone' => ['required', 'string', 'max:10', 'alpha_num'],
            'aisle' => ['required', 'string', 'max:10', 'alpha_num'],
            'bin' => ['required', 'string', 'max:10', 'alpha_num'],
            'type' => ['required', Rule::in(Enums::LOCATION_TYPES)],
            'rack_level' => ['nullable', 'integer', 'min:1', 'max:99'],
            'storage_tier' => ['nullable', Rule::in(Enums::STORAGE_TIERS)],
        ]);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $fullCode = Location::buildFullCode($warehouse->code, $data['zone'], $data['aisle'], $data['bin']);
        // Only a storage location has a tier (#126): the bottom-level surcharge and the putaway check look at storage locations only.
        $data['storage_tier'] = $data['type'] === 'storage' ? ($data['storage_tier'] ?? 'standard') : 'standard';

        Location::query()->firstOrCreate(['warehouse_id' => $warehouse->id, 'full_code' => $fullCode], $data + ['active' => true]);

        return back()->with('status', __('warehouse.locations.created', ['code' => $fullCode]));
    }

    /**
     * 批量设置库位等级 (#126): the storage locations of one warehouse matching zone / aisle from–to / bin from–to get the given rack level
     * and / or storage tier. Each location is saved on its own so activitylog records every change. Numeric codes compare as numbers.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'zone' => ['nullable', 'string', 'max:10'],
            'aisle_from' => ['nullable', 'string', 'max:10'], 'aisle_to' => ['nullable', 'string', 'max:10'],
            'bin_from' => ['nullable', 'string', 'max:10'], 'bin_to' => ['nullable', 'string', 'max:10'],
            'set_rack_level' => ['nullable', 'integer', 'min:1', 'max:99', 'required_without:set_storage_tier'],
            'set_storage_tier' => ['nullable', Rule::in(Enums::STORAGE_TIERS), 'required_without:set_rack_level'],
        ], [
            'set_rack_level.required_without' => __('warehouse.locations.bulk.nothing_to_set'),
            'set_storage_tier.required_without' => __('warehouse.locations.bulk.nothing_to_set'),
        ]);

        $matched = Location::query()->where('warehouse_id', $data['warehouse_id'])->where('type', 'storage')
            ->when(filled($data['zone'] ?? null), fn ($q) => $q->where('zone', strtoupper(trim((string) $data['zone']))))
            ->orderBy('full_code')->get()
            ->filter(fn (Location $l) => $this->within($l->aisle, $data['aisle_from'] ?? null, $data['aisle_to'] ?? null)
                && $this->within($l->bin, $data['bin_from'] ?? null, $data['bin_to'] ?? null));

        $changed = 0;
        foreach ($matched as $location) {
            $location->fill(array_filter([
                'rack_level' => filled($data['set_rack_level'] ?? null) ? (int) $data['set_rack_level'] : null,
                'storage_tier' => $data['set_storage_tier'] ?? null,
            ], fn ($v) => $v !== null));
            if ($location->isDirty()) {
                $location->save();
                $changed++;
            }
        }

        return back()->with('status', __('warehouse.locations.bulk.done', ['changed' => $changed, 'matched' => $matched->count()]));
    }

    private function within(string $value, ?string $from, ?string $to): bool
    {
        return Location::codeBetween($value, $from, $to); // shared with the label print filter (CR #141)
    }
}
