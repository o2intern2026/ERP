<?php

namespace App\Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * CHANGE_REQUESTS #165 库位图: the rack map of one warehouse zone — per aisle, the left-hand (odd bays) and right-hand (even bays) racks as
 * a bay × level grid of slots (position 1 left, 2 right), coloured by what is in them; floor areas listed below. Server-rendered SVG,
 * every slot links to the stock list filtered on its code. Built on the #164 coordinates (zone / aisle / bay / rack_level / position).
 */
class MapController extends Controller
{
    /** Slot states, in the order the legend shows them. */
    public const STATES = ['empty', 'occupied', 'reserved', 'flagged', 'inactive'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'zone' => ['nullable', 'string', 'max:10'],
        ]);
        $warehouses = Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $warehouse = $warehouses->firstWhere('id', (int) ($filters['warehouse_id'] ?? WarehouseContext::currentId() ?? 0)) ?? $warehouses->first();
        if ($warehouse === null) {
            return view('warehouse::map.index', ['warehouses' => $warehouses, 'warehouse' => null, 'zones' => collect(), 'zone' => null, 'aisles' => [], 'floor' => collect(), 'occupancy' => collect(), 'states' => self::STATES, 'totals' => ['slots' => 0, 'occupied' => 0]]);
        }

        $locations = Location::query()->where('warehouse_id', $warehouse->id)->orderBy('full_code')->get();
        $occupancy = $this->occupancy($warehouse->id);
        $rack = $locations->filter(fn (Location $l): bool => $l->isRack());
        $zones = $rack->groupBy('zone')->map(function (Collection $slots, string $zone) use ($occupancy): array {
            $occupied = $slots->filter(fn (Location $l): bool => $occupancy->has($l->id))->count();

            return ['zone' => $zone, 'slots' => $slots->count(), 'occupied' => $occupied, 'percent' => $slots->count() > 0 ? (int) round($occupied / $slots->count() * 100) : 0];
        })->sortKeys();
        $zone = strtoupper((string) ($filters['zone'] ?? '')) ?: (string) ($zones->keys()->first() ?? '');
        $zoneSlots = $rack->where('zone', $zone);

        // aisle → side (left = odd bays, right = even) → ['bays' => ordered bay codes, 'levels' => [top … 1], 'slots' => [bay][level][position] => Location]
        $aisles = [];
        foreach ($zoneSlots->groupBy('aisle')->sortKeys() as $aisle => $aisleSlots) {
            foreach (['left', 'right'] as $side) {
                $sideSlots = $aisleSlots->filter(fn (Location $l): bool => ($l->side() ?? 'left') === $side);
                if ($sideSlots->isEmpty()) {
                    continue;
                }
                $bays = $sideSlots->pluck('bay')->unique()->sort(fn (string $a, string $b): int => ctype_digit($a) && ctype_digit($b) ? ((int) $a <=> (int) $b) : strcmp($a, $b))->values()->all();
                $levels = range((int) $sideSlots->max('rack_level'), 1);
                $grid = [];
                foreach ($sideSlots as $slot) {
                    $grid[$slot->bay][$slot->rack_level][$slot->position] = $slot;
                }
                $aisles[$aisle][$side] = ['bays' => $bays, 'levels' => $levels, 'slots' => $grid];
            }
        }

        return view('warehouse::map.index', [
            'warehouses' => $warehouses,
            'warehouse' => $warehouse,
            'zones' => $zones,
            'zone' => $zone,
            'aisles' => $aisles,
            'floor' => $locations->filter(fn (Location $l): bool => ! $l->isRack()),
            'occupancy' => $occupancy,
            'states' => self::STATES,
            'totals' => ['slots' => $rack->count(), 'occupied' => $rack->filter(fn (Location $l): bool => $occupancy->has($l->id))->count()],
        ]);
    }

    /**
     * What sits in each location of the warehouse: units, cartons on hand, cartons reserved, non-good units, the clients — one grouped query.
     *
     * @return Collection<int, object{location_id:int, units:int, cartons:int, reserved:int, flagged:int, clients:int, client_id:int, client_name:?string, state:string}>
     */
    public static function occupancy(int $warehouseId): Collection
    {
        $rows = StockUnit::query()
            ->where('warehouse_id', $warehouseId)
            ->whereNotNull('location_id')
            ->where(fn ($q) => $q->where('qty_on_hand', '>', 0)->orWhere('qty_reserved', '>', 0))
            ->selectRaw("location_id, count(*) as units, sum(qty_on_hand) as cartons, sum(qty_reserved) as reserved, sum(`condition` <> 'good') as flagged, count(distinct client_id) as clients, min(client_id) as client_id, count(distinct pallet_id) as pallets")
            ->groupBy('location_id')
            ->get()
            ->keyBy('location_id');
        $names = Client::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck('client_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(function (object $row) use ($names): object {
            $row->client_name = $names[$row->client_id] ?? null;
            $row->state = (int) $row->flagged > 0 ? 'flagged' : ((int) $row->reserved > 0 ? 'reserved' : 'occupied');

            return $row;
        });
    }

    /** The legend colour of a slot state — inline SVG attributes, so app.css stays under its 100 lines. */
    public static function fill(string $state): string
    {
        return match ($state) {
            'occupied' => '#43a047',
            'reserved' => '#1e88e5',
            'flagged' => '#e53935',
            'inactive' => '#bdbdbd',
            default => '#eceff1',
        };
    }
}
