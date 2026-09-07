<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\MasterData\MasterDataServiceProvider;
use App\Modules\Orders\OrdersServiceProvider;
use App\Modules\Platform\PlatformServiceProvider;
use App\Modules\Portal\PortalServiceProvider;
use App\Modules\Reports\ReportsServiceProvider;
use App\Modules\Transport\TransportServiceProvider;
use App\Modules\Warehouse\WarehouseServiceProvider;
use App\Providers\AppServiceProvider;
use App\Support\Fakes\FakeServicesProvider;
use App\Support\SupportServiceProvider;

return [
    AppServiceProvider::class,
    SupportServiceProvider::class,
    FakeServicesProvider::class,

    // One provider per module (ERP_PLAN §8.3). Owners: C = Platform, MasterData, Warehouse, Billing;
    // X1 = Orders, Portal, Reports; X2 = Transport.
    PlatformServiceProvider::class,
    MasterDataServiceProvider::class,
    WarehouseServiceProvider::class,
    BillingServiceProvider::class,
    OrdersServiceProvider::class,
    PortalServiceProvider::class,
    ReportsServiceProvider::class,
    TransportServiceProvider::class,
];
