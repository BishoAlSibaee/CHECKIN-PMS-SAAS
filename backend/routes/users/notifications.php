<?php

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// No permission middleware — every authenticated staff member has their own
// notification feed; NotificationController itself filters what each user
// actually sees (by hotel and by their held permissions).
Route::get('notifications', [NotificationController::class, 'index']);
Route::post('notifications/seen', [NotificationController::class, 'markSeen']);
