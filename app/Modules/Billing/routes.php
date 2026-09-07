<?php

use App\Modules\Billing\Http\Controllers\ChargeCodeController;
use App\Modules\Billing\Http\Controllers\ChargeController;
use App\Modules\Billing\Http\Controllers\InvoiceController;
use App\Modules\Billing\Http\Controllers\QuoteController;
use App\Modules\Billing\Http\Controllers\RateCardController;
use App\Modules\Billing\Http\Controllers\ReceivableController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — Billing owns /billing/** (name prefix "billing."). Finance pages are for admin / finance;
// quotes are shared with customer service (FIN-6 / OMS-3).
Route::prefix('billing')->name('billing.')->group(function () {
    Route::middleware('role:admin|finance|customer_service')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
        Route::get('/quotes/create', [QuoteController::class, 'create'])->name('quotes.create');
        Route::post('/quotes', [QuoteController::class, 'store'])->name('quotes.store');
        Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show')->whereNumber('quote');
        Route::post('/quotes/{quote}/status', [QuoteController::class, 'status'])->name('quotes.status');
    });

    Route::middleware('role:admin|finance')->group(function () {
        Route::get('/', [ChargeController::class, 'index'])->name('index');
        Route::get('/charges/review', [ChargeController::class, 'review'])->name('charges.review');
        Route::post('/charges/{charge}/review', [ChargeController::class, 'storeReview'])->name('charges.review.store');
        Route::get('/charges/manual', [ChargeController::class, 'manual'])->name('charges.manual');
        Route::post('/charges/manual', [ChargeController::class, 'storeManual'])->name('charges.manual.store');
        Route::post('/charges/{charge}/reverse', [ChargeController::class, 'reverse'])->name('charges.reverse');
        Route::get('/unbilled', [InvoiceController::class, 'unbilled'])->name('unbilled');

        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('/invoices/job/{job}', [InvoiceController::class, 'draftJob'])->name('invoices.draft_job');
        Route::post('/invoices/monthly', [InvoiceController::class, 'draftMonthly'])->name('invoices.draft_monthly');
        Route::post('/invoices/storage', [InvoiceController::class, 'draftStorage'])->name('invoices.draft_storage');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show')->whereNumber('invoice');
        Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'payment'])->name('invoices.payments.store');
        Route::post('/invoices/{invoice}/credit-notes', [InvoiceController::class, 'creditNote'])->name('invoices.credit_notes.store');
        Route::post('/credit-notes/{note}/issue', [InvoiceController::class, 'issueCreditNote'])->name('credit_notes.issue');
        Route::get('/receivables', [ReceivableController::class, 'index'])->name('receivables');

        Route::get('/charge-codes', [ChargeCodeController::class, 'index'])->name('charge_codes.index');
        Route::get('/rate-cards', [RateCardController::class, 'index'])->name('rate_cards.index');
        Route::post('/rate-cards', [RateCardController::class, 'store'])->name('rate_cards.store');
        Route::get('/rate-cards/{card}', [RateCardController::class, 'show'])->name('rate_cards.show');
        Route::post('/rate-cards/{card}/new-version', [RateCardController::class, 'newVersion'])->name('rate_cards.new_version');
        Route::post('/rate-cards/{card}/items', [RateCardController::class, 'addItem'])->name('rate_cards.items.store');
        Route::post('/rate-items/{item}', [RateCardController::class, 'updateItem'])->name('rate_items.update');
        Route::post('/rate-cards/{card}/request-activation', [RateCardController::class, 'requestActivation'])->name('rate_cards.request_activation');
        Route::post('/rate-cards/{card}/activate', [RateCardController::class, 'activate'])->name('rate_cards.activate');
    });
});
