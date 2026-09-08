<?php

use App\Modules\Portal\Http\Controllers\PortalDocumentController;
use App\Modules\Portal\Http\Controllers\PortalOrderController;
use App\Modules\Portal\Http\Controllers\PortalReturnController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Portal route lives under /portal with the "portal." name prefix.
// Client-role users can reach only /portal/** (ClientScope middleware); the data layer limits them to their own client.
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalOrderController::class, 'index'])->name('index');
    Route::get('/orders/create', [PortalOrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [PortalOrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [PortalOrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/returns', [PortalReturnController::class, 'store'])->name('orders.returns.store');
    Route::get('/documents/{document}', PortalDocumentController::class)->name('documents.download');
});
