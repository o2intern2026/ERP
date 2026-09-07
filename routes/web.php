<?php

use Illuminate\Support\Facades\Route;

/*
 * Frozen zone (contracts/routes.md). This file only requires each module's routes.php; every module wraps its own
 * routes in the URL / name prefixes registered in contracts/routes.md (RoutesContractTest enforces the table).
 *
 * Platform owns /login and /logout, so it applies `auth` itself. Every other module sits behind `auth` and the
 * global client scope (M1/A1): client-role users only see their own client's rows and only reach /portal/**.
 */

require base_path('app/Modules/Platform/routes.php');

Route::middleware(['auth', 'client.scope'])->group(function () {
    foreach (['MasterData', 'Warehouse', 'Billing', 'Orders', 'Portal', 'Reports', 'Transport'] as $module) {
        require base_path("app/Modules/{$module}/routes.php");
    }
});
