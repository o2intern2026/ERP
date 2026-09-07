<?php

use App\Modules\Platform\Http\Controllers\ActivityController;
use App\Modules\Platform\Http\Controllers\ApprovalController;
use App\Modules\Platform\Http\Controllers\DocumentController;
use App\Modules\Platform\Http\Controllers\ExceptionController;
use App\Modules\Platform\Http\Controllers\IntegrationController;
use App\Modules\Platform\Http\Controllers\JobController;
use App\Modules\Platform\Http\Controllers\LoginController;
use App\Modules\Platform\Http\Controllers\SearchController;
use App\Modules\Platform\Http\Controllers\UserController;
use App\Modules\Platform\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — Platform owns "/", /login, /logout, /admin/** and /jobs/** (name prefix "platform.").
$staff = 'role:admin|customer_service|dispatcher|warehouse_supervisor|warehouse_operator|transport_operator|finance';

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('platform.login');
    Route::post('/login', [LoginController::class, 'store'])->name('platform.login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('platform.logout');

Route::redirect('/', '/jobs')->name('platform.home');

Route::middleware(['auth', 'client.scope'])->name('platform.')->group(function () use ($staff) {
    Route::prefix('jobs')->group(function () {
        Route::get('/', [JobController::class, 'index'])->name('index');
        Route::get('/create', [JobController::class, 'create'])->name('jobs.create');
        Route::post('/', [JobController::class, 'store'])->name('jobs.store');
        Route::get('/{job}', [JobController::class, 'show'])->name('jobs.show');
    });

    Route::prefix('admin')->group(function () use ($staff) {
        // Staff-wide tools (A28 exceptions, A29 documents, A30 search, A19 approvals).
        Route::middleware($staff)->group(function () {
            Route::get('/exceptions', [ExceptionController::class, 'index'])->name('exceptions.index');
            Route::post('/exceptions/{exception}/assign', [ExceptionController::class, 'assign'])->name('exceptions.assign');
            Route::post('/exceptions/{exception}/start', [ExceptionController::class, 'start'])->name('exceptions.start');
            Route::post('/exceptions/{exception}/resolve', [ExceptionController::class, 'resolve'])->name('exceptions.resolve');

            Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
            Route::post('/documents/{document}/visibility', [DocumentController::class, 'visibility'])->name('documents.visibility');
            Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

            Route::get('/search', [SearchController::class, 'index'])->name('search');

            Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
            Route::post('/approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])->name('approvals.cancel');
            Route::middleware('role:admin|finance')->group(function () {
                Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
                Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
            });
        });

        // Administration (A1 users, A31 integration monitor, A20 audit log, A23 webhooks).
        Route::middleware('role:admin')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

            Route::get('/integration', [IntegrationController::class, 'index'])->name('integration.index');
            Route::post('/integration/{event}/retry', [IntegrationController::class, 'retry'])->name('integration.retry');

            Route::get('/activity', [ActivityController::class, 'index'])->name('activity.index');

            Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
            Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
            Route::post('/webhooks/{endpoint}/toggle', [WebhookController::class, 'toggle'])->name('webhooks.toggle');
            Route::delete('/webhooks/{endpoint}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
        });
    });
});
