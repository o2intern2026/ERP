<?php

use App\Modules\Platform\Http\Controllers\IntegrationController;
use App\Modules\Platform\Http\Controllers\JobController;
use App\Modules\Platform\Http\Controllers\LoginController;
use App\Modules\Platform\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — Platform owns "/", /login, /logout, /admin/** and /jobs/** (name prefix "platform.").

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('platform.login');
    Route::post('/login', [LoginController::class, 'store'])->name('platform.login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('platform.logout');

Route::redirect('/', '/jobs')->name('platform.home');

Route::middleware(['auth', 'client.scope'])->name('platform.')->group(function () {
    Route::prefix('jobs')->group(function () {
        Route::get('/', [JobController::class, 'index'])->name('index');
        Route::get('/create', [JobController::class, 'create'])->name('jobs.create');
        Route::post('/', [JobController::class, 'store'])->name('jobs.store');
        Route::get('/{job}', [JobController::class, 'show'])->name('jobs.show');
    });

    Route::prefix('admin')->middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::get('/integration', [IntegrationController::class, 'index'])->name('integration.index');
        Route::post('/integration/{event}/retry', [IntegrationController::class, 'retry'])->name('integration.retry');
    });
});
