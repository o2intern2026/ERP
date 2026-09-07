<?php

use App\Modules\Transport\Http\Controllers\DriverController;
use App\Modules\Transport\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — Transport owns /transport/** and /driver/** (name prefix "transport.").
Route::prefix('transport')->name('transport.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
});

Route::prefix('driver')->name('transport.')->group(function () {
    Route::get('/', DriverController::class)->name('driver');
});
