<?php

use App\Http\Controllers\Channex\ChannexWebhookController;
use Illuminate\Support\Facades\Route;

// Inbound Channex webhook — unauthenticated (Channex isn't a staff/kiosk
// client), verified via a shared-secret header instead. Registered in
// RouteServiceProvider under its own throttle, deliberately outside the
// "api" middleware group (same reasoning as routes/kiosk.php).

Route::post('webhook', [ChannexWebhookController::class, 'handle']);
