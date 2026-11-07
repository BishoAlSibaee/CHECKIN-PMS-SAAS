<?php

use App\Http\Controllers\Grms\GrmsChargeController;
use App\Http\Controllers\Grms\GrmsEventsController;
use App\Http\Controllers\Grms\GrmsReconcileController;
use App\Http\Controllers\Grms\GrmsSnapshotController;
use App\Http\Controllers\Grms\GrmsWebhookController;
use Illuminate\Support\Facades\Route;

// Inbound GRMS webhooks — unauthenticated by Laravel's own guards (GRMS
// isn't a staff/kiosk client), verified via a shared-secret header or HMAC
// signature instead. Registered in RouteServiceProvider under its own
// throttle, deliberately outside the "api" middleware group (same reasoning
// as routes/kiosk.php and routes/channex.php).

// Legacy, kept working during the pms_protocol migration (see hotels.pms_protocol).
Route::post('room-ready', [GrmsWebhookController::class, 'roomReady']);

// GRMS v1 contract.
Route::post('v1/events', [GrmsEventsController::class, 'receive']);
Route::post('v1/charges', [GrmsChargeController::class, 'store']);
Route::delete('v1/charges/{chargeId}', [GrmsChargeController::class, 'destroy']);
Route::get('v1/snapshot', [GrmsSnapshotController::class, 'show']);
Route::get('v1/reconcile', [GrmsReconcileController::class, 'show']);
