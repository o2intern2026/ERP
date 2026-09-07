<?php

use App\Modules\Portal\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Portal route lives under /portal with the "portal." name prefix.
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
});
