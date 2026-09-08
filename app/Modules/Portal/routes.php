<?php

use App\Modules\Portal\Http\Controllers\PortalDocumentController;
use App\Modules\Portal\Http\Controllers\PortalEstimateController;
use App\Modules\Portal\Http\Controllers\PortalOrderController;
use App\Modules\Portal\Http\Controllers\PortalQuoteController;
use App\Modules\Portal\Http\Controllers\PortalReportController;
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
    Route::post('/orders/{order}/estimate', PortalEstimateController::class)->name('orders.estimate'); // A7b 估价 (客户价)
    Route::post('/orders/{order}/quotes/{quote}/confirm', [PortalQuoteController::class, 'confirm'])->whereNumber('quote')->name('orders.quotes.confirm'); // §5.7 #2 客户确认最终运输方案
    // A21 客户视角 for client users: ClientScope confines them to /portal/**, so the Reports module's client report is served here (CHANGE_REQUESTS #52).
    Route::get('/reports', [PortalReportController::class, 'index'])->name('reports');
    Route::get('/reports/export/{table}', [PortalReportController::class, 'export'])->name('reports.export');
    Route::get('/documents/{document}', PortalDocumentController::class)->name('documents.download');
});
