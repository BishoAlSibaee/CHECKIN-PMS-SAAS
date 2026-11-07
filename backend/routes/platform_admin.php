<?php

use App\Http\Controllers\GrmsCredentialController;
use App\Http\Controllers\GrmsOutboxAdminController;
use App\Http\Controllers\PlatformAdminAuthController;
use App\Http\Controllers\PlatformAdminsController;
use App\Http\Controllers\TenantsController;
use Illuminate\Support\Facades\Route;

// Mounted at prefix "api/platform-admin" (see RouteServiceProvider), served
// by the standalone PlatformAdmin Angular app only. Deliberately its own
// guard/model (see App\Models\PlatformAdmin) — no account belonging to any
// tenant's hotel can authenticate here, regardless of permissions.
Route::post('login', [PlatformAdminAuthController::class, 'login'])->middleware('throttle:login');
Route::post('forgot-password', [PlatformAdminAuthController::class, 'forgotPassword'])->middleware('throttle:login');
Route::post('reset-password', [PlatformAdminAuthController::class, 'resetPassword'])->middleware('throttle:login');

Route::middleware('auth:platform_admin')->group(function () {
    Route::get('me', [PlatformAdminAuthController::class, 'me']);

    Route::get('admins', [PlatformAdminsController::class, 'index']);
    Route::post('admins', [PlatformAdminsController::class, 'store']);

    Route::get('tenants', [TenantsController::class, 'index']);
    Route::post('tenants', [TenantsController::class, 'store']);
    Route::get('tenants/{tenant}', [TenantsController::class, 'show']);
    Route::put('tenants/{tenant}', [TenantsController::class, 'update']);
    Route::post('tenants/{tenant}/hotels', [TenantsController::class, 'addHotel']);
    Route::post('tenants/{tenant}/renew', [TenantsController::class, 'renewSubscription']);

    Route::get('hotels/{hotel}/grms-credentials', [GrmsCredentialController::class, 'index']);
    Route::post('hotels/{hotel}/grms-credentials', [GrmsCredentialController::class, 'generate']);
    Route::post('grms-credentials/{credential}/rotate', [GrmsCredentialController::class, 'rotate']);
    Route::patch('hotels/{hotel}/grms-protocol', [GrmsCredentialController::class, 'updateProtocol']);

    Route::get('hotels/{hotel}/grms-outbox-events', [GrmsOutboxAdminController::class, 'index']);
    Route::post('grms-outbox-events/{event}/retry', [GrmsOutboxAdminController::class, 'retry']);
});
