<?php

use App\Modules\Portal\Http\Controllers\PortalAddressSuggestionController;
use App\Modules\Portal\Http\Controllers\PortalAsnController;
use App\Modules\Portal\Http\Controllers\PortalDocumentController;
use App\Modules\Portal\Http\Controllers\PortalEstimateController;
use App\Modules\Portal\Http\Controllers\PortalInvoiceController;
use App\Modules\Portal\Http\Controllers\PortalOrderCancelController;
use App\Modules\Portal\Http\Controllers\PortalOrderController;
use App\Modules\Portal\Http\Controllers\PortalQuoteController;
use App\Modules\Portal\Http\Controllers\PortalReportController;
use App\Modules\Portal\Http\Controllers\PortalReturnController;
use App\Modules\Portal\Http\Controllers\PortalStockController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Portal route lives under /portal with the "portal." name prefix.
// Client-role users can reach only /portal/** (ClientScope middleware); the data layer limits them to their own client.
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/', [PortalOrderController::class, 'index'])->name('index');
    Route::get('/orders/create', [PortalOrderController::class, 'create'])->name('orders.create');
    Route::get('/addresses/suggest', PortalAddressSuggestionController::class)->name('addresses.suggest'); // item 3b: JSON, the signed-in client's own addresses
    Route::post('/orders', [PortalOrderController::class, 'store'])->name('orders.store');
    Route::post('/orders/preview', [PortalOrderController::class, 'preview'])->name('orders.preview'); // tester feedback #10: 获取估价 before 确认提交
    Route::get('/orders/{order}', [PortalOrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/returns', [PortalReturnController::class, 'store'])->name('orders.returns.store');
    Route::post('/orders/{order}/cancel', [PortalOrderCancelController::class, 'cancel'])->name('orders.cancel'); // stage 1: client cancels directly (CR #111)
    Route::post('/orders/{order}/cancel-request', [PortalOrderCancelController::class, 'request'])->name('orders.cancel_request'); // stage 2: client asks, coordinator decides
    Route::post('/orders/{order}/estimate', PortalEstimateController::class)->name('orders.estimate'); // A7b 估价 (客户价)
    Route::post('/orders/{order}/quotes/{quote}/confirm', [PortalQuoteController::class, 'confirm'])->whereNumber('quote')->name('orders.quotes.confirm'); // §5.7 #2 客户确认最终运输方案
    // A21 客户视角 for client users: ClientScope confines them to /portal/**, so the Reports module's client report is served here (CHANGE_REQUESTS #52).
    Route::get('/reports', [PortalReportController::class, 'index'])->name('reports.index'); // contracts/routes.md
    Route::get('/reports/export/{table}', [PortalReportController::class, 'export'])->name('reports.export');
    // §7 step 8 / §3.8 #5: the client's invoices (GST-inclusive PDFs through DocumentDownloader) and its own stock, read-only.
    Route::get('/invoices', [PortalInvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}/download', [PortalInvoiceController::class, 'download'])->whereNumber('invoice')->name('invoices.download');
    Route::get('/stock', [PortalStockController::class, 'index'])->name('stock.index');
    Route::get('/documents/{document}', PortalDocumentController::class)->name('documents.download');
    // CHANGE_REQUESTS #117 预报入库, read-only: the ASNs staff opened for the client's goods (progress, lines, 入库单 PDFs). Clients do not create ASNs.
    Route::get('/asns', [PortalAsnController::class, 'index'])->name('asns.index');
    Route::get('/asns/{asn}', [PortalAsnController::class, 'show'])->whereNumber('asn')->name('asns.show');
});
