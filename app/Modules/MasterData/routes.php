<?php

use App\Modules\MasterData\Http\Controllers\CarrierController;
use App\Modules\MasterData\Http\Controllers\ClientController;
use App\Modules\MasterData\Http\Controllers\SupplierController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — MasterData owns /admin/clients/**, /admin/suppliers/**, /admin/carriers/** (name prefix "masterdata.").
// Master data is maintained by admin, customer service and finance (PLT-2). Warehouses / locations live in Warehouse.
Route::prefix('admin')->name('masterdata.')->middleware('role:admin|customer_service|finance')->group(function () {
    // CHANGE_REQUESTS #134 (audit A13 / GAP-01): staff never create clients — companies register at /register (Platform
    // RegistrationController binds the standard rate card) and are approved, edited and managed here afterwards.
    Route::get('/clients', [ClientController::class, 'index'])->name('index');
    Route::get('/clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::post('/clients/{client}/approve', [ClientController::class, 'approve'])->name('clients.approve'); // tester feedback #8: activate a self-registered client + its users
    Route::post('/clients/{client}/bind-standard-card', [ClientController::class, 'bindStandardCard'])->middleware('role:admin')->name('clients.bind_standard_card'); // #134 修复: a client with no standard card gets the active one

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
