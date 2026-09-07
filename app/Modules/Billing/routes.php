<?php

use App\Modules\Billing\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Billing route lives under /billing with the "billing." name prefix.
Route::prefix('billing')->name('billing.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
});
