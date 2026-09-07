<?php

use App\Modules\Warehouse\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Warehouse route lives under /warehouse with the "warehouse." name prefix.
Route::prefix('warehouse')->name('warehouse.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
});
