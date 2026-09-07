<?php

/*
 * Frozen zone (contracts/routes.md). This file only requires each module's routes.php;
 * every module wraps its own routes in the URL / name prefixes registered in contracts/routes.md.
 * tests/Feature/Platform/RoutesContractTest.php enforces the table.
 */

foreach (['Platform', 'MasterData', 'Warehouse', 'Billing', 'Orders', 'Portal', 'Reports', 'Transport'] as $module) {
    require base_path("app/Modules/{$module}/routes.php");
}
