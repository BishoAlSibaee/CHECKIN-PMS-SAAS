<?php

use App\Http\Controllers\Grms\GrmsWebhookController;
use Illuminate\Support\Facades\Route;

// Inbound GRMS webhook — unauthenticated (GRMS isn't a staff/kiosk client),
// verified via a shared-secret header instead. Registered in
// RouteServiceProvider under its own throttle, deliberately outside the
// "api" middleware group (same reasoning as routes/kiosk.php and
// routes/channex.php).

Route::post('room-ready', [GrmsWebhookController::class, 'roomReady']);
