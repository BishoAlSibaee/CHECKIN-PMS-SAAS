<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\EarningController;
use App\Http\Controllers\FinancialDashboardController;
use App\Http\Controllers\NightAuditController;
use App\Http\Controllers\RefundPolicyController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RevenueController;
use Illuminate\Support\Facades\Route;

// Money domain: earnings/payments/refunds, revenue breakdowns, the financial
// dashboard, double-entry accounting, and refund policy automation. Split out
// of routes/users.php — same routes, same "api/users" prefix, unchanged.

Route::middleware(['permission:view earnings,api'])->group(function () {
    Route::get('all-earnings', [EarningController::class, 'allEarnings']);
    Route::get('earnings-list', [EarningController::class, 'earningsList']);
    Route::get('earnings-summary', [EarningController::class, 'earningsSummary']);
    Route::get('payments', [EarningController::class, 'payments']);
    Route::get('refunds', [EarningController::class, 'refunds']);
});

Route::middleware(['permission:view accounting reports,api'])->group(function () {
    Route::get('accounting/chart-of-accounts', [AccountingController::class, 'chartOfAccounts']);
    Route::get('accounting/journal-entries', [AccountingController::class, 'journalEntries']);
});

Route::middleware(['permission:manage journal entries,api'])->group(function () {
    Route::post('accounting/journal-entries', [AccountingController::class, 'storeJournalEntry']);
});

Route::middleware(['permission:close accounting period,api'])->group(function () {
    Route::post('accounting/periods/close', [AccountingController::class, 'closePeriod']);
});

Route::middleware(['permission:manage night audit,api'])->group(function () {
    Route::get('night-audit/runs', [NightAuditController::class, 'index']);
    Route::post('night-audit/run', [NightAuditController::class, 'store']);
});

Route::middleware(['permission:view revenue,api', 'permission:view earnings,api'])->group(function () {
    Route::get('financials/bounds', [FinancialDashboardController::class, 'bounds']);
    Route::get('financials/dashboard', [FinancialDashboardController::class, 'show']);
});

Route::middleware(['permission:view revenue,api'])->group(function () {
    Route::get('revenue/total', [RevenueController::class, 'getTotalRevenue']);
    Route::get('revenue/room/{entity_id}', [RevenueController::class, 'getRoomRevenue']);
    Route::get('revenue/suite/{entity_id}', [RevenueController::class, 'getSuiteRevenue']);
    Route::get('revenue/floor/{entity_id}', [RevenueController::class, 'getFloorRevenue']);
    Route::get('revenue/building/{entity_id}', [RevenueController::class, 'getBuildingRevenue']);
    Route::get('revenue/roomtype/{entity_id}', [RevenueController::class, 'getRoomTypeRevenue']);
});

Route::middleware(['permission:manage refunds,api'])->group(function () {
    Route::post('refund', [ReservationController::class, 'refund']);
    Route::get('refund-policies/preview', [RefundPolicyController::class, 'preview']);
});

Route::middleware(['permission:manage refund policies,api'])->group(function () {
    Route::apiResource('refund-policies', RefundPolicyController::class);
    Route::delete('refund-policies', [RefundPolicyController::class, 'destroy']);
});
