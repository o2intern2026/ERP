<?php

use App\Modules\Transport\Http\Controllers\ConsignmentNoteController;
use App\Modules\Transport\Http\Controllers\DeliveryRunController;
use App\Modules\Transport\Http\Controllers\DriverController;
use App\Modules\Transport\Http\Controllers\IndexController;
use App\Modules\Transport\Http\Controllers\QuoteSelectionController;
use App\Modules\Transport\Http\Controllers\RunStopController;
use App\Modules\Transport\Http\Controllers\ShipmentController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — Transport owns /transport/** and /driver/** (name prefix "transport.").
Route::prefix('transport')->name('transport.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
    Route::get('/runs', [DeliveryRunController::class, 'index'])->name('runs.index');
    Route::get('/runs/create', [DeliveryRunController::class, 'create'])->name('runs.create');
    Route::post('/runs', [DeliveryRunController::class, 'store'])->name('runs.store');
    Route::get('/runs/{deliveryRun}', [DeliveryRunController::class, 'show'])
        ->whereNumber('deliveryRun')
        ->name('runs.show');
    Route::post('/runs/{deliveryRun}/stops', [RunStopController::class, 'store'])
        ->whereNumber('deliveryRun')
        ->name('runs.stops.store');
    Route::patch('/runs/{deliveryRun}/stops/order', [RunStopController::class, 'reorder'])
        ->whereNumber('deliveryRun')
        ->name('runs.stops.reorder');
    Route::get('/{shipment}/consignment-note', ConsignmentNoteController::class)
        ->whereNumber('shipment')
        ->name('shipments.consignment-note');
    Route::post('/{shipment}/quotes/{quote}/select', QuoteSelectionController::class)
        ->whereNumber(['shipment', 'quote'])
        ->name('shipments.quotes.select');
    Route::get('/{shipment}', [ShipmentController::class, 'show'])
        ->whereNumber('shipment')
        ->name('shipments.show');
});

Route::prefix('driver')->name('transport.')->group(function () {
    Route::get('/', DriverController::class)->name('driver');
});
