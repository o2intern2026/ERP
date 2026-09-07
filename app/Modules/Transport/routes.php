<?php

use App\Modules\Transport\Http\Controllers\ConsignmentNoteController;
use App\Modules\Transport\Http\Controllers\DriverController;
use App\Modules\Transport\Http\Controllers\IndexController;
use App\Modules\Transport\Http\Controllers\ShipmentController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — Transport owns /transport/** and /driver/** (name prefix "transport.").
Route::prefix('transport')->name('transport.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
    Route::get('/{shipment}/consignment-note', ConsignmentNoteController::class)
        ->whereNumber('shipment')
        ->name('shipments.consignment-note');
    Route::get('/{shipment}', [ShipmentController::class, 'show'])
        ->whereNumber('shipment')
        ->name('shipments.show');
});

Route::prefix('driver')->name('transport.')->group(function () {
    Route::get('/', DriverController::class)->name('driver');
});
