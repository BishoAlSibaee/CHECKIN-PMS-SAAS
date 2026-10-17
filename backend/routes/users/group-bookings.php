<?php

use App\Http\Controllers\GroupBookingController;
use Illuminate\Support\Facades\Route;

// Group/event bookings — a group can span multiple independent Reservation
// rows (different clients/dates), same "api/users" prefix and hotel-context
// group as the other domain files.

Route::middleware(['permission:view group bookings,api'])->group(function () {
    Route::get('group-bookings', [GroupBookingController::class, 'index']);
    Route::get('group-bookings/{id}', [GroupBookingController::class, 'show']);
    Route::get('group-bookings/{id}/financials', [GroupBookingController::class, 'financials']);
});

Route::middleware(['permission:create group bookings,api'])->post('group-bookings', [GroupBookingController::class, 'store']);

Route::middleware(['permission:cancel group bookings,api'])->post('group-bookings/{id}/cancel', [GroupBookingController::class, 'cancel']);
