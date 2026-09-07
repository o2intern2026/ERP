<?php

namespace App\Modules\Transport\Seeders;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Transport\Models\CarrierService;
use Illuminate\Database\Seeder;

class TransportSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            'OWN' => [
                ['source' => 'own_fleet', 'service_level' => 'standard', 'default_eta_days' => 1],
            ],
            'TRANSDIRECT' => [
                ['source' => 'manual', 'service_level' => 'standard', 'default_eta_days' => 3],
                ['source' => 'transdirect', 'service_level' => 'standard', 'default_eta_days' => 3],
                ['source' => 'transdirect', 'service_level' => 'express', 'default_eta_days' => 1],
                ['source' => 'transdirect', 'service_level' => 'same_day', 'default_eta_days' => 0],
            ],
        ];

        foreach ($services as $carrierCode => $rows) {
            $carrier = Carrier::query()->where('code', $carrierCode)->first();
            if ($carrier === null) {
                continue;
            }

            foreach ($rows as $row) {
                CarrierService::query()->updateOrCreate(
                    [
                        'carrier_id' => $carrier->id,
                        'source' => $row['source'],
                        'service_level' => $row['service_level'],
                    ],
                    $row + ['active' => true, 'config' => null],
                );
            }
        }
    }
}
