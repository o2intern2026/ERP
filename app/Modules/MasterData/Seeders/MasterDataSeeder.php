<?php

namespace App\Modules\MasterData\Seeders;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\MasterData\Models\Supplier;
use Illuminate\Database\Seeder;

/** Demo master data for the §7 acceptance script: a per_job client, a monthly client and a prepaid client. */
class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'EDWARD', 'name' => 'Edward Logistics', 'leg_type' => 'both', 'payment_terms' => 'eom', 'invoice_mode' => 'per_job', 'default_markup_percent' => 20, 'dispatch_cutoff_time' => '14:00', 'state' => 'VIC', 'suburb' => 'Dandenong South', 'postcode' => '3175'],
            ['code' => 'MONTHLY', 'name' => 'Monthly Demo Pty Ltd', 'leg_type' => 'last_leg', 'payment_terms' => 'net_30', 'invoice_mode' => 'monthly', 'default_markup_percent' => 15, 'dispatch_cutoff_time' => '12:00', 'state' => 'NSW', 'suburb' => 'Kemps Creek', 'postcode' => '2178'],
            ['code' => 'PREPAID', 'name' => 'Prepaid Demo Co', 'leg_type' => 'first_leg', 'payment_terms' => 'prepaid', 'invoice_mode' => 'per_job', 'default_markup_percent' => 25, 'dispatch_cutoff_time' => null, 'state' => 'VIC', 'suburb' => 'Bayswater North', 'postcode' => '3153'],
        ] as $client) {
            Client::query()->updateOrCreate(['code' => $client['code']], $client + ['status' => 'active']);
        }

        Supplier::query()->updateOrCreate(['code' => 'PALLETS'], ['name' => 'Pallet Supply Demo', 'status' => 'active']);

        foreach ([
            ['code' => 'OWN', 'name' => 'Own fleet'],
            ['code' => 'TRANSDIRECT', 'name' => 'Transdirect'],
            ['code' => 'EIZ', 'name' => 'EIZ'],
            ['code' => 'KARRIO', 'name' => 'Karrio (open-source carrier gateway)'],
        ] as $carrier) {
            Carrier::query()->updateOrCreate(['code' => $carrier['code']], $carrier + ['status' => 'active']);
        }
    }
}
