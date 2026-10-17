<?php

use App\Http\Controllers\DiscountsController;
use App\Http\Controllers\PeakDaysController;
use App\Http\Controllers\PeakMonthsController;
use App\Http\Controllers\TaxesController;
use Illuminate\Support\Facades\Route;

// Pricing rules and modifiers: taxes, peak-day/peak-month multipliers, and
// guest-classification discounts. Split out of routes/users.php — same routes,
// same "api/users" prefix, unchanged.

// =====================================Tax========================================================
Route::middleware(['permission:manage taxes,api'])->group(function () {
    Route::get('/getTax', [TaxesController::class, 'index']);
    Route::post('/addTax', [TaxesController::class, 'store']);
    Route::post('/updateTax', [TaxesController::class, 'update']);
    Route::delete('/deleteTax', [TaxesController::class, 'destroy']);
});

// =====================================PeakDaysCheck===============================================
Route::middleware(['permission:manage peak days,api'])->group(function () {
    Route::get('/getPeakDays', [PeakDaysController::class, 'index']);
    Route::post('/updatePeakDaysCheck', [PeakDaysController::class, 'updateCheck']);
});
Route::middleware(['permission:manage peak months,api'])->group(function () {
    Route::get('/getPeakMonths', [PeakMonthsController::class, 'index']);
    Route::post('/updatePeakMonthsCheck', [PeakMonthsController::class, 'updateCheck']);
});

// =====================================Discounts=================================================
Route::middleware(['role_or_permission:create reservations|manage discounts,api'])->get('getDiscounts', [DiscountsController::class, 'index']);
Route::middleware(['permission:manage discounts,api'])->group(function () {
    Route::post('addDiscount', [DiscountsController::class, 'store']);
    Route::post('updateDiscount', [DiscountsController::class, 'update']);
    Route::delete('deleteDiscount', [DiscountsController::class, 'destroy']);
});
