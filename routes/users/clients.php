<?php

use App\Http\Controllers\ClientClassificationsController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\GuestClassificationFeaturesController;
use App\Http\Controllers\GuestClassificationsController;
use App\Http\Controllers\GuestFeaturesController;
use Illuminate\Support\Facades\Route;

// Guest/CRM domain: client records, staff notes on a client, and guest
// classification + feature tagging used to auto-apply discounts. Split out of
// routes/users.php — same routes, same "api/users" prefix, unchanged.

// =====================================Clients====================================================
Route::middleware(['permission:view clients,api'])->group(function () {
    Route::get('/getClient', [ClientsController::class, 'index']);
    Route::get('/getClient/{id}', [ClientsController::class, 'show']);
    Route::get('/getClientById/{id}', [ClientsController::class, 'getClientById']);
    Route::get('/getClientBy', [ClientsController::class, 'getBy']);
    Route::get('/clients/{id}/statement', [ClientsController::class, 'statement']);
});
Route::middleware(['permission:manage clients,api'])->group(function () {
    Route::post('/addClient', [ClientsController::class, 'store']);
    Route::post('/updateClient', [ClientsController::class, 'update']);
});

// =====================================GuestClassification=======================================
Route::middleware(['permission:manage guest classifications,api'])->group(function () {
    Route::get('/getGuestClassification', [GuestClassificationsController::class, 'index']);
    Route::post('addGuestClassification', [GuestClassificationsController::class, 'store']);
    Route::post('updateGuestClassification', [GuestClassificationsController::class, 'update']);
    Route::post('deleteGuestClassification', [GuestClassificationsController::class, 'destroy']);
    Route::get('getGuestFeature', [GuestFeaturesController::class, 'index']);
    Route::post('addGuestFeature', [GuestFeaturesController::class, 'store']);
    Route::post('updateGuestFeature', [GuestFeaturesController::class, 'update']);
    Route::post('deleteGuestFeature', [GuestFeaturesController::class, 'destroy']);
    Route::get('getGuestClassificationFeature', [GuestClassificationFeaturesController::class, 'index']);
    Route::get('getGuestClassificationFeatureByClassification', [GuestClassificationFeaturesController::class, 'getFeaturesByClassification']);
    Route::post('addGuestClassificationFeature', [GuestClassificationFeaturesController::class, 'store']);
    Route::post('deleteGuestClassificationFeature', [GuestClassificationFeaturesController::class, 'destroy']);
    Route::get('getAllClientsWithClassification', [ClientClassificationsController::class, 'getAllClientsWithClassification']);
    Route::post('assignClientClassification', [ClientClassificationsController::class, 'assignClassification']);
    Route::post('removeClientClassification', [ClientClassificationsController::class, 'removeClassification']);
});

Route::middleware(['role_or_permission:create reservations|manage guest classifications,api'])->group(function () {
    Route::get('getClientClassification', [ClientClassificationsController::class, 'getClientClassification']);
});

// =====================================ClientNotes================================================
Route::middleware(['permission:manage client notes,api'])->group(function () {
    Route::get('client-notes', [ClientNoteController::class, 'index']);
    Route::post('client-notes', [ClientNoteController::class, 'store']);
    Route::put('client-notes', [ClientNoteController::class, 'update']);
    Route::delete('client-notes', [ClientNoteController::class, 'destroy']);
});
