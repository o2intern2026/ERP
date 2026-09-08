<?php

use App\Modules\Reports\Http\Controllers\BossReportController;
use App\Modules\Reports\Http\Controllers\ClientReportController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Reports route lives under /reports with the "reports." name prefix.
// Client users never reach /reports (ClientScope confines them to /portal/**): their client view is /portal/reports (CHANGE_REQUESTS #52).
Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [BossReportController::class, 'index'])->name('index');                              // A21 老板视角 (admin | finance)
    Route::get('/export/{table}', [BossReportController::class, 'export'])->name('export');
    Route::get('/client', [ClientReportController::class, 'index'])->name('client');                     // A21 客户视角 for staff (client filter)
    Route::get('/client/export/{table}', [ClientReportController::class, 'export'])->name('client.export');
});
