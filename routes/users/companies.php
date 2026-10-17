<?php

use App\Http\Controllers\CompanyBillingController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyLedgerController;
use Illuminate\Support\Facades\Route;

// Corporate accounts — a company has a negotiated discount and deferred
// billing; same "api/users" prefix and hotel-context group as the other
// domain files.

Route::middleware(['permission:view companies,api'])->group(function () {
    Route::get('companies', [CompanyController::class, 'index']);
    Route::get('companies/{id}', [CompanyController::class, 'show']);
});

Route::middleware(['permission:manage companies,api'])->group(function () {
    Route::post('companies', [CompanyController::class, 'store']);
    Route::put('companies/{id}', [CompanyController::class, 'update']);
});

Route::middleware(['permission:view company ledger,api'])->group(function () {
    Route::get('companies-ledger', [CompanyLedgerController::class, 'index']);
    Route::get('companies-ledger/{companyId}', [CompanyLedgerController::class, 'statement']);
});

Route::middleware(['permission:record company settlement,api'])->post('companies-ledger/{companyId}/settle', [CompanyLedgerController::class, 'settle']);

Route::middleware(['permission:create company reservations,api'])->post('reservations/company-billed', [CompanyBillingController::class, 'store']);
