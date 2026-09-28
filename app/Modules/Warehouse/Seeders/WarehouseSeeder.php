<?php

namespace App\Modules\Warehouse\Seeders;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * The demo warehouse MEL1 (CHANGE_REQUESTS #164 codes): floor areas as four-part codes, the pickface slots and zone A as a rack grid —
 * aisles 01–02 × bays 01–04 (odd bays left, even right) × levels 1–3 × positions 1–2 — level 1 of zone A is the bottom tier (#126).
 * Idempotent: codes are the key.
 */
class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $mel = Warehouse::query()->updateOrCreate(['code' => 'MEL1'], [
            'name' => 'Melbourne DC', 'address' => '1 Depot Road', 'suburb' => 'Dandenong South', 'state' => 'VIC', 'postcode' => '3175', 'active' => true,
            'business_hours' => ['mon' => ['07:00', '17:00'], 'tue' => ['07:00', '17:00'], 'wed' => ['07:00', '17:00'], 'thu' => ['07:00', '17:00'], 'fri' => ['07:00', '17:00']],
        ]);

        foreach ([['RCV', '01', '01', 'receiving'], ['STG', '01', '01', 'staging'], ['PCK', '01', '01', 'packing'], ['QA', '01', '01', 'quarantine']] as [$zone, $aisle, $bay, $type]) {
            Location::query()->updateOrCreate(
                ['warehouse_id' => $mel->id, 'full_code' => Location::buildFullCode('MEL1', $zone, $aisle, $bay)],
                ['zone' => $zone, 'aisle' => $aisle, 'bay' => $bay, 'type' => $type, 'active' => true],
            );
        }
        foreach ([['PF', 1, 1, 1, 'pickface'], ['PF', 1, 1, 2, 'pickface'], ['PF', 1, 2, 1, 'pickface'], ['PF', 1, 2, 2, 'pickface']] as [$zone, $aisle, $bay, $position, $type]) {
            $this->slot($mel, $zone, $aisle, $bay, 1, $position, $type, 'standard');
        }
        foreach (range(1, 2) as $aisle) {
            foreach (range(1, 4) as $bay) {
                foreach (range(1, 3) as $level) {
                    foreach (Location::POSITIONS as $position) {
                        $this->slot($mel, 'A', $aisle, $bay, $level, $position, 'storage', $level === 1 ? 'bottom' : 'standard');
                    }
                }
            }
        }
    }

    private function slot(Warehouse $warehouse, string $zone, int $aisle, int $bay, int $level, int $position, string $type, string $tier): void
    {
        $aisleCode = sprintf('%02d', $aisle);
        $bayCode = sprintf('%02d', $bay);
        Location::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'full_code' => Location::buildFullCode($warehouse->code, $zone, $aisleCode, $bayCode, $level, $position)],
            ['zone' => $zone, 'aisle' => $aisleCode, 'bay' => $bayCode, 'rack_level' => $level, 'position' => $position, 'type' => $type, 'storage_tier' => $tier, 'active' => true],
        );
    }
}
