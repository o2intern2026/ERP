<?php

namespace App\Modules\Warehouse\Seeders;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $mel = Warehouse::query()->updateOrCreate(['code' => 'MEL'], [
            'name' => 'Melbourne DC', 'address' => 'Dandenong South', 'state' => 'VIC', 'active' => true,
            'business_hours' => ['mon' => ['07:00', '17:00'], 'tue' => ['07:00', '17:00'], 'wed' => ['07:00', '17:00'], 'thu' => ['07:00', '17:00'], 'fri' => ['07:00', '17:00']],
        ]);

        $locations = [['RCV', '01', '01', 'receiving'], ['STG', '01', '01', 'staging'], ['PCK', '01', '01', 'packing'], ['QA', '01', '01', 'quarantine'], ['PF', '01', '01', 'pickface'], ['PF', '01', '02', 'pickface']];
        foreach (range(1, 5) as $aisle) {
            foreach (range(1, 4) as $bin) {
                $locations[] = ['A', sprintf('%02d', $aisle), sprintf('%02d', $bin), 'storage'];
            }
        }
        foreach ($locations as [$zone, $aisle, $bin, $type]) {
            Location::query()->updateOrCreate(
                ['warehouse_id' => $mel->id, 'full_code' => Location::buildFullCode('MEL', $zone, $aisle, $bin)],
                ['zone' => $zone, 'aisle' => $aisle, 'bin' => $bin, 'type' => $type, 'active' => true],
            );
        }
    }
}
