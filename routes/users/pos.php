<?php

use App\Http\Controllers\PosCategoryController;
use App\Http\Controllers\PosChargeController;
use Illuminate\Support\Facades\Route;

// Point of Sale charges (restaurant/cafe/laundry/minibar) — same "api/users"
// prefix and hotel-context group as the other domain files.

Route::middleware(['permission:view pos categories,api'])->get('pos-categories', [PosCategoryController::class, 'index']);
Route::middleware(['permission:manage pos categories,api'])->group(function () {
    Route::get('pos-categories/accounts', [PosCategoryController::class, 'accounts']);
    Route::post('pos-categories', [PosCategoryController::class, 'store']);
    Route::put('pos-categories/{id}', [PosCategoryController::class, 'update']);
});

Route::middleware(['permission:create pos charges,api'])->get('pos-charges/lookup-room', [PosChargeController::class, 'lookupRoom']);

Route::middleware(['permission:view pos charges,api'])->get('pos-charges', [PosChargeController::class, 'index']);
Route::middleware(['permission:create pos charges,api'])->post('pos-charges', [PosChargeController::class, 'store']);
Route::middleware(['permission:void pos charges,api'])->post('pos-charges/{id}/void', [PosChargeController::class, 'void']);
