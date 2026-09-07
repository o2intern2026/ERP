<?php

use App\Modules\MasterData\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every MasterData route lives under /admin/clients with the "masterdata." name prefix.
Route::prefix('admin/clients')->name('masterdata.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
});
