<?php

use App\Modules\Platform\Http\Controllers\ActivityController;
use App\Modules\Platform\Http\Controllers\ApprovalController;
use App\Modules\Platform\Http\Controllers\DocumentController;
use App\Modules\Platform\Http\Controllers\ExceptionController;
use App\Modules\Platform\Http\Controllers\IntegrationController;
use App\Modules\Platform\Http\Controllers\JobController;
use App\Modules\Platform\Http\Controllers\LoginController;
use App\Modules\Platform\Http\Controllers\PasswordController;
use App\Modules\Platform\Http\Controllers\RegistrationController;
use App\Modules\Platform\Http\Controllers\SearchController;
use App\Modules\Platform\Http\Controllers\UserController;
use App\Modules\Platform\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — Platform owns "/", /login, /logout, /account/**, /admin/** and /jobs/** (name prefix "platform.").
$staff = 'role:admin|customer_service|dispatcher|warehouse_supervisor|warehouse_operator|transport_operator|finance';
// CR #137 (audit ADMIN-08 / CRAWL-05): the driver executes (CR #130) — Jobs are opened by the office and the warehouse lead, never from the truck.
$jobCreators = 'role:admin|customer_service|dispatcher|warehouse_supervisor';
// CR #137 (audit ADMIN-08 / ADMIN-10): uploading and flipping 客户可见 is for the roles that own a client relationship; floor roles and the driver read and download.
$documentEditors = 'role:admin|customer_service|finance|warehouse_supervisor';

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('platform.login');
    Route::post('/login', [LoginController::class, 'store'])->name('platform.login.store');
    Route::get('/register', [RegistrationController::class, 'show'])->name('platform.register'); // tester feedback #8: client self-registration
    Route::post('/register', [RegistrationController::class, 'store'])->name('platform.register.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('platform.logout');

// CR #137 (audit TMS-15): "/" lands where login does — the driver on /driver, other staff on /jobs, clients on /portal; a guest goes to /jobs → /login.
Route::get('/', [LoginController::class, 'home'])->name('platform.home');

// CR #137 (audit ADMIN-13): every signed-in user changes their own password here — staff and client users alike (ClientScope admits /account/**).
Route::middleware(['auth', 'client.scope'])->prefix('account')->name('platform.')->group(function () {
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'client.scope'])->name('platform.')->group(function () use ($staff, $jobCreators, $documentEditors) {
    Route::prefix('jobs')->group(function () use ($jobCreators) {
        Route::get('/', [JobController::class, 'index'])->name('index');
        Route::get('/create', [JobController::class, 'create'])->middleware($jobCreators)->name('jobs.create');
        Route::post('/', [JobController::class, 'store'])->middleware($jobCreators)->name('jobs.store');
        Route::get('/{job}', [JobController::class, 'show'])->name('jobs.show');
    });

    Route::prefix('admin')->group(function () use ($staff, $documentEditors) {
        // Staff-wide tools (A28 exceptions, A29 documents, A30 search, A19 approvals).
        Route::middleware($staff)->group(function () use ($documentEditors) {
            Route::get('/exceptions', [ExceptionController::class, 'index'])->name('exceptions.index');
            Route::post('/exceptions/{exception}/assign', [ExceptionController::class, 'assign'])->name('exceptions.assign');
            Route::post('/exceptions/{exception}/start', [ExceptionController::class, 'start'])->name('exceptions.start');
            Route::post('/exceptions/{exception}/resolve', [ExceptionController::class, 'resolve'])->name('exceptions.resolve');

            Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('/documents', [DocumentController::class, 'store'])->middleware($documentEditors)->name('documents.store');
            Route::post('/documents/{document}/visibility', [DocumentController::class, 'visibility'])->middleware($documentEditors)->name('documents.visibility');
            Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

            Route::get('/search', [SearchController::class, 'index'])->name('search');

            // CR #142 (lead decision 2026-09-22): the approval centre — list and every action — is for the roles that approve rate-card changes and
            // credit notes (admin | finance; they are also the only requesters). Other roles see no nav entry and get 403; a requester follows the
            // status of their own request on the item's page (rate card / invoice).
            Route::middleware('role:admin|finance')->group(function () {
                Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
                Route::post('/approvals/{approval}/cancel', [ApprovalController::class, 'cancel'])->name('approvals.cancel');
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
