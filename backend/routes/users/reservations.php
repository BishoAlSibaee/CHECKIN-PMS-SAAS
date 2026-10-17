<?php

use App\Http\Controllers\CollectionsController;
use App\Http\Controllers\PenaltiesController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationSourcesController;
use App\Http\Controllers\RoomBoardController;
use App\Http\Controllers\StayReasonsController;
use Illuminate\Support\Facades\Route;

// Booking/operations domain: stay reasons, penalties, reservation sources, the
// core reservation lifecycle (search/create/update/cancel/folio), the occupancy
// board, and payment collections. Split out of routes/users.php — same routes,
// same "api/users" prefix, unchanged.

// =====================================StayReason================================================
Route::middleware(['role_or_permission:create reservations|manage stay reasons,api'])->group(function () {
    Route::get('/getStayReasons', [StayReasonsController::class, 'index']);
});
Route::middleware(['permission:manage stay reasons,api'])->group(function () {
    Route::post('/addStayReason', [StayReasonsController::class, 'store']);
    Route::post('/updateStayReason', [StayReasonsController::class, 'update']);
    Route::delete('/deleteStayReason', [StayReasonsController::class, 'destroy']);
});

// =====================================Penaltie===================================================
Route::middleware(['role_or_permission:update reservations|manage penalties,api'])->get('/getPenalties', [PenaltiesController::class, 'index']);
Route::middleware(['permission:manage penalties,api'])->group(function () {
    Route::post('/addPenaltie', [PenaltiesController::class, 'store']);
    Route::post('/updatePenaltie', [PenaltiesController::class, 'update']);
    Route::delete('/deletePenaltie', [PenaltiesController::class, 'destroy']);
});
Route::middleware(['permission:update reservations,api'])->group(function () {
    Route::post('/addReservationPenalty', [PenaltiesController::class, 'addReservationPenalty']);
    Route::get('/getPenaltiesByReservationId', [PenaltiesController::class, 'getByReservation']);
});

// =====================================ReservationSources=========================================
Route::middleware(['role_or_permission:create reservations|manage reservation sources,api'])->get('/getReservationSource', [ReservationSourcesController::class, 'index']);
Route::middleware(['permission:manage reservation sources,api'])->group(function () {
    Route::post('/addReservationSource', [ReservationSourcesController::class, 'store']);
    Route::post('/updateReservationSource', [ReservationSourcesController::class, 'update']);
    Route::delete('/deleteReservationSource', [ReservationSourcesController::class, 'destroy']);
});

// =========================================Reservation=============================================
Route::middleware(['permission:view payments,api'])->group(function () {
    Route::get('collections/summary', [CollectionsController::class, 'summary']);
    Route::get('collections', [CollectionsController::class, 'index']);
});

Route::middleware(['permission:update reservations,api'])->group(function () {
    Route::post('collections/collect-all', [CollectionsController::class, 'collectAll']);
});

Route::middleware(['permission:view reservations,api'])->group(function () {
    Route::get('reservations', [ReservationController::class, 'index']);
    Route::get('reservations/calendar', [ReservationController::class, 'calendar']);
    Route::get('reservations/by-date', [ReservationController::class, 'getReservationByDate']);
    Route::get('reservations/client', [ReservationController::class, 'getByClientId']);
    Route::get('reservations/{id}', [ReservationController::class, 'show']);
    Route::get('reservations/{id}/folio', [ReservationController::class, 'folio']);
    Route::get('checkReservation', [ReservationController::class, 'checkReservation']);
    Route::get('booking-room-availability', [ReservationController::class, 'bookingRoomAvailability']);
    Route::post('getRoomPrice', [ReservationController::class, 'getRoomPrice']);
    Route::get('rooms/occupancy-board', [RoomBoardController::class, 'occupancyBoard']);
});

Route::middleware(['permission:create reservations,api'])->group(function () {
    Route::post('makeReservation', [ReservationController::class, 'makeReservation']);
});

Route::middleware(['permission:update reservations,api'])->group(function () {
    Route::get('reservations/{id}/date-change-preview', [ReservationController::class, 'previewDateChange']);
    Route::patch('reservations/{id}', [ReservationController::class, 'update']);
    Route::patch('reservations/{id}/extend', [ReservationController::class, 'extend']);
    Route::patch('reservations/{id}/shorten', [ReservationController::class, 'shorten']);
    Route::post('reservations/{id}/payments', [ReservationController::class, 'addPayment']);
    Route::post('reservations/{id}/redeem-points', [ReservationController::class, 'redeemLoyaltyPoints']);
});

Route::middleware(['permission:cancel reservations,api'])->group(function () {
    Route::post('reservations/{id}/cancel', [ReservationController::class, 'cancel']);
    Route::post('reservations/{id}/rooms/{roomId}/cancel', [ReservationController::class, 'cancelRoom']);
});
