<?php

use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\SystemHealthController;
use Illuminate\Support\Facades\Route;

// Reporting domain: the report catalog/runner, queued exports, and the system
// health check. Split out of routes/users.php — same routes, same "api/users"
// prefix, unchanged.

Route::middleware(['permission:view reports,api'])->group(function () {
    Route::get('reports/catalog', [ReportController::class, 'catalog']);
    Route::get('reports/exports', [ReportExportController::class, 'index']);
    Route::get('reports/{slug}/download', [ReportExportController::class, 'downloadNow']);
    Route::post('reports/{slug}/email-request', [ReportExportController::class, 'requestEmail']);
    Route::get('reports/{slug}', [ReportController::class, 'run']);
});

// Report runners need mail_configured without requiring "view users".
Route::middleware(['permission:view users|view reports,api'])->get('system/health', [SystemHealthController::class, 'index']);
