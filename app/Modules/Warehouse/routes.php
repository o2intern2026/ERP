<?php

use App\Modules\Warehouse\Http\Controllers\AsnController;
use App\Modules\Warehouse\Http\Controllers\LocationController;
use App\Modules\Warehouse\Http\Controllers\PutawayController;
use App\Modules\Warehouse\Http\Controllers\ReceivingController;
use App\Modules\Warehouse\Http\Controllers\StockController;
use App\Modules\Warehouse\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Warehouse route lives under /warehouse with the "warehouse." name prefix.
Route::prefix('warehouse')->name('warehouse.')->group(function () {
    Route::middleware('role:admin|warehouse_supervisor|warehouse_operator|dispatcher|customer_service|finance')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('/stock/{unit}', [StockController::class, 'show'])->name('stock.show');
        Route::get('/reservations', [StockController::class, 'reservations'])->name('reservations.index');
        Route::get('/asns', [AsnController::class, 'index'])->name('asns.index');
        Route::get('/asns/{asn}', [AsnController::class, 'show'])->name('asns.show')->whereNumber('asn');
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/config/locations', [LocationController::class, 'index'])->name('locations.index');
    });

    Route::middleware('role:admin|warehouse_supervisor|warehouse_operator|customer_service')->group(function () {
        Route::get('/asns/create', [AsnController::class, 'create'])->name('asns.create');
        Route::post('/asns', [AsnController::class, 'store'])->name('asns.store');
        Route::post('/asns/{asn}/lines', [AsnController::class, 'storeLine'])->name('asns.lines.store');
        Route::post('/asns/{asn}/import', [AsnController::class, 'import'])->name('asns.import');
        Route::post('/asns/{asn}/arrive', [AsnController::class, 'arrive'])->name('asns.arrive');
        Route::post('/asns/{asn}/confirm-unplanned', [AsnController::class, 'confirmUnplanned'])->name('asns.confirm_unplanned');
    });

    Route::middleware('role:admin|warehouse_supervisor|warehouse_operator')->group(function () {
        Route::get('/asns/{asn}/lines/{line}/receive', [ReceivingController::class, 'form'])->name('receiving.form');
        Route::post('/asns/{asn}/lines/{line}/receive', [ReceivingController::class, 'store'])->name('receiving.store');
        Route::get('/putaway', [PutawayController::class, 'index'])->name('putaway.index');
        Route::post('/putaway/{unit}', [PutawayController::class, 'store'])->name('putaway.store');
        Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::post('/config/locations', [LocationController::class, 'store'])->name('locations.store');
    });
});
