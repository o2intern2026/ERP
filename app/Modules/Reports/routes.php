<?php

use App\Modules\Reports\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Reports route lives under /reports with the "reports." name prefix.
Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
});
