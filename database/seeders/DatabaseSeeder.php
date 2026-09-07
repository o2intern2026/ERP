<?php

namespace Database\Seeders;

use App\Modules\Billing\Seeders\BillingSeeder;
use App\Modules\MasterData\Seeders\MasterDataSeeder;
use App\Modules\Orders\Seeders\OrdersSeeder;
use App\Modules\Platform\Seeders\PlatformSeeder;
use App\Modules\Portal\Seeders\PortalSeeder;
use App\Modules\Reports\Seeders\ReportsSeeder;
use App\Modules\Transport\Seeders\TransportSeeder;
use App\Modules\Warehouse\Seeders\WarehouseSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Bare call list — one line per module, each seat edits only its own line (AGENTS.md).
     */
    public function run(): void
    {
        $this->call([MasterDataSeeder::class, // C (clients first: Platform demo users reference them)

            PlatformSeeder::class,     // C
            WarehouseSeeder::class,   // C
            BillingSeeder::class,       // C
            OrdersSeeder::class,         // X1
            PortalSeeder::class,         // X1
            ReportsSeeder::class,       // X1
            TransportSeeder::class,   // X2
        ]);
    }
}
