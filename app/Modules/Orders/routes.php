<?php

use App\Modules\Orders\Http\Controllers\ClientAddressController;
use App\Modules\Orders\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Orders route lives under /orders with the "orders." name prefix.
Route::prefix('orders')->name('orders.')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::get('/create', [OrderController::class, 'create'])->name('create');
    Route::post('/', [OrderController::class, 'store'])->name('store');
    Route::get('/addresses', [ClientAddressController::class, 'index'])->name('addresses.index');
    Route::get('/addresses/create', [ClientAddressController::class, 'create'])->name('addresses.create');
    Route::post('/addresses', [ClientAddressController::class, 'store'])->name('addresses.store');
    Route::get('/addresses/{address}/edit', [ClientAddressController::class, 'edit'])->name('addresses.edit');
    Route::put('/addresses/{address}', [ClientAddressController::class, 'update'])->name('addresses.update');
    Route::get('/{order}', [OrderController::class, 'show'])->name('show');
    Route::patch('/{order}', [OrderController::class, 'update'])->name('update');
});
