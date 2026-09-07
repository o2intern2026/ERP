<?php

namespace Tests\Support;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Warehouse;

/** A warehouse with one receiving, storage, pickface and quarantine location each. */
trait CreatesWarehouse
{
    protected function warehouse(string $code = 'MEL'): Warehouse
    {
        $warehouse = Warehouse::query()->firstOrCreate(['code' => $code], ['name' => $code.' DC', 'state' => 'VIC', 'active' => true]);

        foreach ([['RCV', '01', '01', 'receiving'], ['A', '01', '01', 'storage'], ['A', '01', '02', 'storage'], ['PF', '01', '01', 'pickface'], ['QA', '01', '01', 'quarantine']] as [$zone, $aisle, $bin, $type]) {
            Location::query()->firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'full_code' => Location::buildFullCode($code, $zone, $aisle, $bin)],
                ['zone' => $zone, 'aisle' => $aisle, 'bin' => $bin, 'type' => $type, 'active' => true],
            );
        }

        return $warehouse;
    }

    protected function location(Warehouse $warehouse, string $type): Location
    {
        return Location::query()->where('warehouse_id', $warehouse->id)->where('type', $type)->orderBy('full_code')->firstOrFail();
    }
}
