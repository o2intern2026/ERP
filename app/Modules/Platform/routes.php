<?php

use App\Modules\Platform\Http\Controllers\IndexController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — Platform owns "/", /login, /logout, /admin/** and /jobs/** (name prefix "platform.").
Route::redirect('/', '/jobs')->name('platform.home');

Route::prefix('jobs')->name('platform.')->group(function () {
    Route::get('/', IndexController::class)->name('index');
});
