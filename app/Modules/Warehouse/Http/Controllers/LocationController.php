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

/**
 * Warehouse configuration: locations per warehouse — WH-ZONE-AISLE-BAY[-LEVEL-POSITION] codes (CHANGE_REQUESTS #164), rack level +
 * storage tier (CHANGE_REQUESTS #126), the rack-grid generator.
 */
class LocationController extends Controller
{
    /** Upper bound of one 批量生成 run — enough for a whole zone, small enough to notice a typo in a range. */
    public const MAX_GENERATED = 2000;

    public function index(): View
    {
        return view('warehouse::locations.index', [
            'warehouses' => Warehouse::query()->with(['locations' => fn ($q) => $q->orderBy('full_code')])->orderBy('code')->get(),
            'types' => Enums::LOCATION_TYPES,
            'tiers' => Enums::STORAGE_TIERS,
            'positions' => Location::POSITIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'zone' => ['required', 'string', 'max:10', 'alpha_num'],
            'aisle' => ['required', 'string', 'max:10', 'alpha_num'],
            'bay' => ['required', 'string', 'max:10', 'alpha_num'],
            'type' => ['required', Rule::in(Enums::LOCATION_TYPES)],
            // #164: a level always comes with its slot (1 left / 2 right) and vice versa — a floor area has neither.
            'rack_level' => ['nullable', 'integer', 'min:1', 'max:99', 'required_with:position'],
            'position' => ['nullable', 'integer', Rule::in(Location::POSITIONS), 'required_with:rack_level'],
            'storage_tier' => ['nullable', Rule::in(Enums::STORAGE_TIERS)],
        ]);
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $fullCode = Location::buildFullCode($warehouse->code, $data['zone'], $data['aisle'], $data['bay'], $data['rack_level'] ?? null, $data['position'] ?? null);
        // Only a storage location has a tier (#126): the bottom-level surcharge and the putaway check look at storage locations only.
        $data['storage_tier'] = $data['type'] === 'storage' ? ($data['storage_tier'] ?? 'standard') : 'standard';
        Location::query()->firstOrCreate(['warehouse_id' => $warehouse->id, 'full_code' => $fullCode], $data + ['active' => true]);

        return back()->with('status', __('warehouse.locations.created', ['code' => $fullCode]));
    }

    /**
     * CHANGE_REQUESTS #164 批量生成库位: a rack grid — zone × aisle range × bay range × levels × positions — as six-part codes
     * (numbers zero-padded to two digits, so string order is walk order; odd bays left, even right). Existing codes are skipped;
     * level 1 can be marked the bottom tier (#126) when the grid is storage.
     */
    public function generate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'zone' => ['required', 'string', 'max:10', 'alpha_num'],
            'aisle_from' => ['required', 'integer', 'min:1', 'max:99'],
            'aisle_to' => ['required', 'integer', 'min:1', 'max:99', 'gte:aisle_from'],
            'bay_from' => ['required', 'integer', 'min:1', 'max:99'],
            'bay_to' => ['required', 'integer', 'min:1', 'max:99', 'gte:bay_from'],
            'levels' => ['required', 'integer', 'min:1', 'max:9'],
            'positions' => ['required', 'integer', Rule::in(Location::POSITIONS)],
            'type' => ['required', Rule::in(['storage', 'pickface'])],
            'bottom_level_one' => ['nullable', 'boolean'],
        ]);
        $total = ($data['aisle_to'] - $data['aisle_from'] + 1) * ($data['bay_to'] - $data['bay_from'] + 1) * $data['levels'] * $data['positions'];
        if ($total > self::MAX_GENERATED) {
            return back()->withInput()->withErrors(['bay_to' => __('warehouse.locations.generate.too_many', ['max' => self::MAX_GENERATED, 'total' => $total])]);
        }

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $zone = strtoupper($data['zone']);
        $bottom = $data['type'] === 'storage' && (bool) ($data['bottom_level_one'] ?? false);
        $created = 0;
        $skipped = 0;
        for ($aisle = (int) $data['aisle_from']; $aisle <= (int) $data['aisle_to']; $aisle++) {
            for ($bay = (int) $data['bay_from']; $bay <= (int) $data['bay_to']; $bay++) {
                for ($level = 1; $level <= (int) $data['levels']; $level++) {
                    for ($position = 1; $position <= (int) $data['positions']; $position++) {
                        $aisleCode = sprintf('%02d', $aisle);
                        $bayCode = sprintf('%02d', $bay);
                        $location = Location::query()->firstOrCreate(
                            ['warehouse_id' => $warehouse->id, 'full_code' => Location::buildFullCode($warehouse->code, $zone, $aisleCode, $bayCode, $level, $position)],
                            [
                                'zone' => $zone, 'aisle' => $aisleCode, 'bay' => $bayCode, 'rack_level' => $level, 'position' => $position,
                                'type' => $data['type'], 'storage_tier' => $bottom && $level === 1 ? 'bottom' : 'standard', 'active' => true,
                            ],
                        );
                        $location->wasRecentlyCreated ? $created++ : $skipped++;
                    }
                }
            }
        }

        return back()->with('status', __('warehouse.locations.generate.done', ['created' => $created, 'skipped' => $skipped]));
    }

    /**
     * 批量设置库位等级 (#126): the storage locations of one warehouse matching zone / aisle from–to / bay from–to get the given rack level
     * and / or storage tier. Each location is saved on its own so activitylog records every change; a level change re-codes a rack slot
     * (Location::booted). Numeric codes compare as numbers.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'zone' => ['nullable', 'string', 'max:10'],
            'aisle_from' => ['nullable', 'string', 'max:10'], 'aisle_to' => ['nullable', 'string', 'max:10'],
            'bay_from' => ['nullable', 'string', 'max:10'], 'bay_to' => ['nullable', 'string', 'max:10'],
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
                && $this->within($l->bay, $data['bay_from'] ?? null, $data['bay_to'] ?? null));
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
