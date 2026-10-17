<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

// This file is mounted at prefix "api/users" (see RouteServiceProvider) — that
// prefix predates this split and is kept as-is here to avoid a breaking change
// for the frontend/kiosk clients already calling these URLs. What changed is
// only how the routes are organized on disk: instead of one ~380-line file,
// each business domain now lives in its own file under routes/users/, required
// below in the same order/grouping the routes were originally defined in, so
// every URL, method, controller action and middleware is unchanged — verified
// with `route:list` before and after this split.

Route::post('login', [UsersController::class, 'login'])->middleware('throttle:login');
Route::get('setup-check', [UsersController::class, 'setupCheck']);

// Token-based report download (email links work without an active session).
Route::get('reports/exports/{export}/download', [ReportExportController::class, 'download'])
    ->middleware('throttle:report-download');

Route::get('login_error', [UsersController::class, 'loginError'])->name('login');

Route::group(['middleware' => ['auth:api', 'resolve.hotel']], function () {
    // Dashboard home — any authenticated staff (no extra permission gate)
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

    require base_path('routes/users/property.php');
    require base_path('routes/users/pricing.php');
    require base_path('routes/users/reservations.php');
    require base_path('routes/users/group-bookings.php');
    require base_path('routes/users/companies.php');
    require base_path('routes/users/pos.php');
    require base_path('routes/users/external-subscriptions.php');
    require base_path('routes/users/clients.php');
    require base_path('routes/users/financials.php');
    require base_path('routes/users/reports.php');
    require base_path('routes/users/admin.php');
    require base_path('routes/users/channex.php');
    require base_path('routes/users/notifications.php');
});
