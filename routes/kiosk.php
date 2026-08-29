<?php

use App\Http\Controllers\KioskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kiosk (self-service device) API routes
|--------------------------------------------------------------------------
|
| Mounted under api/kiosk by RouteServiceProvider. Authenticated by a
| per-device bearer token (kiosk.auth middleware) rather than staff
| Passport login — see App\Http\Middleware\AuthenticateKioskDevice.
| The same Spatie permission middleware used by the staff routes is
| stacked on top for defense in depth.
|
*/

Route::middleware(['kiosk.auth'])->group(function () {
    Route::middleware(['permission:view reservations,api'])->group(function () {
        Route::get('rooms', [KioskController::class, 'rooms']);
        Route::get('reservations/lookup', [KioskController::class, 'lookupReservation']);
    });

    Route::middleware(['permission:view clients,api'])->get('clients/lookup', [KioskController::class, 'lookupClient']);
    Route::middleware(['permission:manage clients,api'])->post('clients', [KioskController::class, 'createClient']);

    Route::middleware(['permission:create reservations,api'])->post('reservations', [KioskController::class, 'makeReservation']);

    Route::middleware(['permission:update reservations,api'])
        ->post('reservations/{id}/confirm-payment', [KioskController::class, 'confirmPayment']);
});
