<?php

use App\Modules\Orders\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Orders route lives under /orders with the "orders." name prefix.
Route::prefix('orders')->name('orders.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
});
