<?php

use App\Http\Controllers\Channex\AirbnbRequestSettingController;
use App\Http\Controllers\Channex\ChannexConnectionController;
use App\Http\Controllers\Channex\ChannexMessageController;
use App\Http\Controllers\Channex\ChannexReservationRequestController;
use App\Http\Controllers\Channex\ChannexReviewController;
use App\Http\Controllers\Channex\OtaAllocationController;
use App\Http\Controllers\Channex\OtaRatePlanController;
use App\Http\Controllers\PlatformLedgerController;
use Illuminate\Support\Facades\Route;

// Channel-manager domain (Channex): OTA room allocation and the per-platform
// ledger statement. Same "api/users" prefix, same auth/hotel-context group,
// split out per routes/users.php's existing domain-file convention.

Route::middleware(['permission:manage ota allocation,api'])->group(function () {
    Route::get('channex/ota-allocations', [OtaAllocationController::class, 'index']);
    Route::post('channex/ota-allocations', [OtaAllocationController::class, 'update']);
});

Route::middleware(['permission:view platform ledger,api'])->group(function () {
    Route::get('channex/platform-ledger', [PlatformLedgerController::class, 'index']);
    Route::get('channex/platform-ledger/{reservationSourceId}', [PlatformLedgerController::class, 'statement']);
});

Route::middleware(['permission:manage ota allocation,api'])->group(function () {
    Route::get('channex/rate-plans', [OtaRatePlanController::class, 'index']);
    Route::post('channex/rate-plans', [OtaRatePlanController::class, 'store']);
    Route::post('channex/rate-plans/{id}', [OtaRatePlanController::class, 'update']);
    Route::delete('channex/rate-plans/{id}', [OtaRatePlanController::class, 'destroy']);

    Route::get('channex/connection', [ChannexConnectionController::class, 'status']);
    Route::post('channex/connection/sync', [ChannexConnectionController::class, 'syncNow']);
});

Route::middleware(['permission:manage airbnb requests,api'])->group(function () {
    Route::get('channex/airbnb-requests', [ChannexReservationRequestController::class, 'index']);
    Route::post('channex/airbnb-requests/{id}/accept', [ChannexReservationRequestController::class, 'accept']);
    Route::post('channex/airbnb-requests/{id}/decline', [ChannexReservationRequestController::class, 'decline']);
});

Route::middleware(['permission:manage airbnb settings,api'])->group(function () {
    Route::get('channex/airbnb-settings', [AirbnbRequestSettingController::class, 'show']);
    Route::post('channex/airbnb-settings', [AirbnbRequestSettingController::class, 'update']);
});

Route::middleware(['permission:manage channex reviews,api'])->group(function () {
    Route::get('channex/reviews', [ChannexReviewController::class, 'index']);
    Route::post('channex/reviews/{reviewId}/reply', [ChannexReviewController::class, 'reply']);
    Route::get('channex/reviews-scores', [ChannexReviewController::class, 'scores']);
});

Route::middleware(['permission:manage channex messages,api'])->group(function () {
    Route::get('channex/messages', [ChannexMessageController::class, 'index']);
    Route::get('channex/messages/{threadId}', [ChannexMessageController::class, 'messages']);
    Route::post('channex/messages/{threadId}/reply', [ChannexMessageController::class, 'reply']);
    Route::post('channex/messages/{threadId}/no-reply-needed', [ChannexMessageController::class, 'noReplyNeeded']);
    Route::post('channex/messages/{threadId}/close', [ChannexMessageController::class, 'close']);
});
