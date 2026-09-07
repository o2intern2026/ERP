<?php

use App\Modules\MasterData\Http\Controllers\CarrierController;
use App\Modules\MasterData\Http\Controllers\ClientController;
use App\Modules\MasterData\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — MasterData owns /admin/clients/**, /admin/suppliers/**, /admin/carriers/** (name prefix "masterdata.").
// Master data is maintained by admin, customer service and finance (PLT-2). Warehouses / locations live in Warehouse.
Route::prefix('admin')->name('masterdata.')->middleware('role:admin|customer_service|finance')->group(function () {
    Route::get('/clients', [ClientController::class, 'index'])->name('index');
    Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');

    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
    Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');

    Route::get('/carriers', [CarrierController::class, 'index'])->name('carriers.index');
    Route::get('/carriers/create', [CarrierController::class, 'create'])->name('carriers.create');
    Route::post('/carriers', [CarrierController::class, 'store'])->name('carriers.store');
    Route::get('/carriers/{carrier}/edit', [CarrierController::class, 'edit'])->name('carriers.edit');
    Route::put('/carriers/{carrier}', [CarrierController::class, 'update'])->name('carriers.update');
});
