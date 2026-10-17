<?php

use App\Http\Controllers\BuildingsController;
use App\Http\Controllers\DynamicPricingRuleController;
use App\Http\Controllers\FacilitiesController;
use App\Http\Controllers\FeaturesController;
use App\Http\Controllers\FloorController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomTypesController;
use App\Http\Controllers\SuiteController;
use Illuminate\Support\Facades\Route;

// Property structure: buildings -> floors -> suites/rooms, room types and their
// pricing plans, plus the facilities/features catalog attached to them.
// Split out of routes/users.php — same routes, same "api/users" prefix, unchanged.

// =========================================Buildings=============================================
Route::middleware(['permission:view buildings,api'])->get('buildings', [BuildingsController::class, 'index']);
Route::middleware(['permission:manage buildings,api'])->group(function () {
    Route::post('updateBuilding', [BuildingsController::class, 'update']);
    Route::post('building', [BuildingsController::class, 'store']);
    Route::delete('building', [BuildingsController::class, 'destroy']);
});

// =========================================Floor=============================================
Route::middleware(['permission:view floors,api'])->get('floors', [FloorController::class, 'index']);
Route::middleware(['permission:manage floors,api'])->group(function () {
    Route::post('addFloor', [FloorController::class, 'addFloor']);
    Route::delete('deleteFloor', [FloorController::class, 'deleteFloor']);
    Route::post('updateFloor', [FloorController::class, 'updateFloor']);
});

// =========================================Suite=============================================
Route::middleware(['permission:view suites,api'])->get('suites', [SuiteController::class, 'index']);
Route::middleware(['permission:manage suites,api'])->group(function () {
    Route::post('addSuite', [SuiteController::class, 'addSuite']);
    Route::delete('deleteSuite', [SuiteController::class, 'deleteSuite']);
    Route::post('updateSuite', [SuiteController::class, 'updateSuite']);
});

// =========================================Room=============================================
Route::middleware(['permission:view rooms,api'])->get('rooms', [RoomController::class, 'index']);
Route::middleware(['permission:manage rooms,api'])->group(function () {
    Route::post('addRoom', [RoomController::class, 'addRoom']);
    Route::delete('deleteRoom', [RoomController::class, 'deleteRoom']);
    Route::post('updateRoom', [RoomController::class, 'updateRoom']);
    Route::post('addMultiRoom', [RoomController::class, 'addMultiRoom']);
});

// =====================================RoomTypesController====================================================
Route::middleware(['permission:view room types,api'])->group(function () {
    Route::get('/getRoomType', [RoomTypesController::class, 'getRoomType']);
    Route::get('/getRoomtypePricing', [RoomTypesController::class, 'getRoomtypePricing']);
});
Route::middleware(['permission:manage room types,api'])->group(function () {
    Route::post('/addRoomType', [RoomTypesController::class, 'addRoomType']);
    Route::post('/updateRoomType', [RoomTypesController::class, 'updateRoomType']);
    Route::post('/deleteRoomType', [RoomTypesController::class, 'deleteRoomType']);
});
Route::middleware(['permission:manage pricing plans,api'])->group(function () {
    Route::post('/addRoomtypePricing', [RoomTypesController::class, 'addRoomtypePricing']);
    Route::post('/addRoomtypePricingPlan', [RoomTypesController::class, 'addRoomtypePricingPlan']);
    Route::post('/updateRoomtypePricing', [RoomTypesController::class, 'updateRoomtypePricing']);
    Route::post('/deleteRoomtypePricing', [RoomTypesController::class, 'deleteRoomtypePricing']);
});

Route::middleware(['permission:view room types,api'])->get('room-types/{roomTypeId}/dynamic-pricing-rules', [DynamicPricingRuleController::class, 'index']);
Route::middleware(['permission:manage pricing plans,api'])->group(function () {
    Route::post('room-types/{roomTypeId}/dynamic-pricing-rules', [DynamicPricingRuleController::class, 'store']);
    Route::put('dynamic-pricing-rules/{id}', [DynamicPricingRuleController::class, 'update']);
    Route::delete('dynamic-pricing-rules/{id}', [DynamicPricingRuleController::class, 'destroy']);
});

// =====================================Facilities=================================================
Route::middleware(['permission:manage facilities,api'])->group(function () {
    Route::get('/getFacilities', [FacilitiesController::class, 'index']);
    Route::post('/addFacilities', [FacilitiesController::class, 'store']);
    Route::post('/updateFacilities', [FacilitiesController::class, 'update']);
    Route::post('/deleteFacilities', [FacilitiesController::class, 'destroy']);
    Route::get('/getFacilitiesByBuilding', [FacilitiesController::class, 'getByBuilding']);
    Route::get('/getFacilitiesByFloor', [FacilitiesController::class, 'getByFloor']);
    Route::get('/getFacilitiesType', [FacilitiesController::class, 'typeIndex']);
    Route::post('/addFacilitiesType', [FacilitiesController::class, 'typeStore']);
    Route::post('/updateFacilitiesType', [FacilitiesController::class, 'typeUpdate']);
    Route::delete('/deleteFacilitiesType', [FacilitiesController::class, 'typeDestroy']);
    Route::get('/getFacilitiesTypeById', [FacilitiesController::class, 'typeShow']);
});

// =====================================Features=================================================
Route::middleware(['permission:manage features,api'])->group(function () {
    Route::get('/getFeature', [FeaturesController::class, 'index']);
    Route::post('/addFeature', [FeaturesController::class, 'store']);
    Route::post('/updateFeature', [FeaturesController::class, 'update']);
    Route::delete('/deleteFeature', [FeaturesController::class, 'destroy']);
    Route::get('/getRoomFeature', [FeaturesController::class, 'roomIndex']);
    Route::post('/addRoomFeature', [FeaturesController::class, 'roomStore']);
    Route::post('/updateRoomFeature', [FeaturesController::class, 'roomUpdate']);
    Route::delete('/deleteRoomFeature', [FeaturesController::class, 'roomDestroy']);
    Route::get('/getRoomFeatureByRoom', [FeaturesController::class, 'getByRoom']);
});
