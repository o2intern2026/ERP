<?php

use App\Modules\Orders\Http\Controllers\AddressSuggestionController;
use App\Modules\Orders\Http\Controllers\ApiTokenController;
use App\Modules\Orders\Http\Controllers\BatchController;
use App\Modules\Orders\Http\Controllers\ClientAddressController;
use App\Modules\Orders\Http\Controllers\DraftOrderController;
use App\Modules\Orders\Http\Controllers\FulfilmentController;
use App\Modules\Orders\Http\Controllers\HoldController;
use App\Modules\Orders\Http\Controllers\OrderApiController;
use App\Modules\Orders\Http\Controllers\OrderChangeController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\OrderEstimateController;
use App\Modules\Orders\Http\Controllers\OrderImportController;
use App\Modules\Orders\Http\Controllers\OrderLineController;
use App\Modules\Orders\Http\Controllers\ClientRequestController;
use App\Modules\Orders\Http\Controllers\QueueController;
use App\Modules\Orders\Http\Controllers\ReturnController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Orders route lives under /orders with the "orders." name prefix.
Route::prefix('orders')->name('orders.')->group(function () {
    // A4b: token-authenticated JSON endpoint (no session, no CSRF, no client.scope — the bearer token names the client).
    Route::post('/api/orders', [OrderApiController::class, 'store'])->name('api.orders.store')
        ->withoutMiddleware(['auth', 'client.scope', ValidateCsrfToken::class]);
    Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::post('/api-tokens/{token}/revoke', [ApiTokenController::class, 'revoke'])->name('api-tokens.revoke');
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::get('/create', [OrderController::class, 'create'])->name('create');
    Route::post('/', [OrderController::class, 'store'])->name('store');
    Route::get('/queue', [QueueController::class, 'index'])->name('queue');
    Route::get('/requests', [ClientRequestController::class, 'index'])->name('requests.index'); // 客户请求 inbox: cancel + return requests from the portal (CR #112)
    Route::get('/batches', [BatchController::class, 'index'])->name('batches');
    Route::get('/drafts/create', [DraftOrderController::class, 'create'])->name('drafts.create');
    Route::post('/drafts', [DraftOrderController::class, 'store'])->name('drafts.store');
    Route::get('/imports', [OrderImportController::class, 'index'])->name('imports.index');
    Route::get('/imports/create', [OrderImportController::class, 'create'])->name('imports.create');
    Route::post('/imports/preview', [OrderImportController::class, 'preview'])->name('imports.preview');
    Route::post('/imports/{import}/confirm', [OrderImportController::class, 'confirm'])->name('imports.confirm');
    Route::get('/imports/{import}/errors', [OrderImportController::class, 'errors'])->name('imports.errors');
    Route::get('/imports/{import}', [OrderImportController::class, 'show'])->name('imports.show');
    Route::get('/addresses', [ClientAddressController::class, 'index'])->name('addresses.index');
    Route::get('/addresses/create', [ClientAddressController::class, 'create'])->name('addresses.create');
    Route::get('/addresses/suggest', AddressSuggestionController::class)->name('addresses.suggest'); // item 3b: JSON, client's address book + past deliver-to snapshots
    Route::post('/addresses', [ClientAddressController::class, 'store'])->name('addresses.store');
    Route::get('/addresses/{address}/edit', [ClientAddressController::class, 'edit'])->name('addresses.edit');
    Route::put('/addresses/{address}', [ClientAddressController::class, 'update'])->name('addresses.update');
    Route::post('/{order}/confirm', [OrderController::class, 'confirm'])->name('confirm');
    Route::post('/{order}/tailgate', [OrderController::class, 'tailgate'])->name('tailgate');
    Route::post('/{order}/estimate', [OrderEstimateController::class, 'store'])->name('estimate'); // A7b customer quote / 估价
    Route::post('/{order}/holds', [HoldController::class, 'store'])->name('holds.store');
    Route::post('/{order}/holds/{exception}/release', [HoldController::class, 'release'])->name('holds.release');
    Route::post('/{order}/cancel', [OrderChangeController::class, 'cancel'])->name('cancel');
    Route::post('/{order}/cancel-request/reject', [OrderChangeController::class, 'rejectCancelRequest'])->name('cancel_request.reject'); // client's stage-2 request declined (CR #111)
    Route::post('/{order}/reduce', [OrderChangeController::class, 'reduce'])->name('reduce');
    Route::post('/{order}/returns', [ReturnController::class, 'store'])->name('returns.store');
    Route::post('/{order}/return-decision', [ReturnController::class, 'decide'])->name('returns.decide');
    Route::post('/{order}/lines', [OrderLineController::class, 'store'])->name('lines.store');
    Route::patch('/{order}/lines/{line}', [OrderLineController::class, 'update'])->name('lines.update');
    Route::get('/{order}/fulfilments', [FulfilmentController::class, 'index'])->name('fulfilments.index');
    Route::get('/{order}', [OrderController::class, 'show'])->name('show');
    Route::patch('/{order}', [OrderController::class, 'update'])->name('update');
});
