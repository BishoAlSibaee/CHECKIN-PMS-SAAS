<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\BuildingsController;
use App\Http\Controllers\ClientClassificationsController;
use App\Http\Controllers\ClientNoteController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\CollectionsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\DiscountsController;
use App\Http\Controllers\EarningController;
use App\Http\Controllers\FacilitiesController;
use App\Http\Controllers\FeaturesController;
use App\Http\Controllers\FinancialDashboardController;
use App\Http\Controllers\FloorController;
use App\Http\Controllers\GuestClassificationFeaturesController;
use App\Http\Controllers\GuestClassificationsController;
use App\Http\Controllers\GuestFeaturesController;
use App\Http\Controllers\JobTitlesController;
use App\Http\Controllers\PeakDaysController;
use App\Http\Controllers\PeakMonthsController;
use App\Http\Controllers\PenaltiesController;
use App\Http\Controllers\PermissionsController;
use App\Http\Controllers\RefundPolicyController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationSourcesController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\RoomBoardController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomTypesController;
use App\Http\Controllers\StayReasonsController;
use App\Http\Controllers\SuiteController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\TaxesController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

Route::post('login', [UsersController::class, 'login'])->middleware('throttle:login');
Route::get('setup-check', [UsersController::class, 'setupCheck']);

// Token-based report download (email links work without an active session).
Route::get('reports/exports/{export}/download', [ReportExportController::class, 'download'])
    ->middleware('throttle:report-download');

// Route::middleware(['can_do:manage_settings'])->group(function () {
// });
Route::get('login_error', [UsersController::class, 'loginError'])->name('login');

Route::group(['middleware' => ['auth:api']], function () {
    // Dashboard home — any authenticated staff (no extra permission gate)
    Route::get('dashboard/summary', [DashboardController::class, 'summary']);

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

    // =====================================StayReason================================================
    Route::middleware(['role_or_permission:create reservations|manage stay reasons,api'])->group(function () {
        Route::get('/getStayReasons', [StayReasonsController::class, 'index']);
    });
    Route::middleware(['permission:manage stay reasons,api'])->group(function () {
        Route::post('/addStayReason', [StayReasonsController::class, 'store']);
        Route::post('/updateStayReason', [StayReasonsController::class, 'update']);
        Route::delete('/deleteStayReason', [StayReasonsController::class, 'destroy']);
    });

    // =====================================Permissions=================================================
    Route::middleware(['permission:manage permissions,api'])->group(function () {
        Route::get('/getPermissions', [PermissionsController::class, 'index']);
        Route::post('/updatePermission', [PermissionsController::class, 'update']);
        Route::delete('/deletePermission', [PermissionsController::class, 'destroy']);
        Route::post('/addUserPermission', [PermissionsController::class, 'addUserPermission']);
    });

    // =====================================Discounts=================================================
    Route::middleware(['role_or_permission:create reservations|manage discounts,api'])->get('getDiscounts', [DiscountsController::class, 'index']);
    Route::middleware(['permission:manage discounts,api'])->group(function () {
        Route::post('addDiscount', [DiscountsController::class, 'store']);
        Route::post('updateDiscount', [DiscountsController::class, 'update']);
        Route::delete('deleteDiscount', [DiscountsController::class, 'destroy']);
    });

    // =====================================JobTitle===================================================
    Route::middleware(['role_or_permission:view users|manage job titles,api'])->group(function () {
        Route::get('/getJobTitle', [JobTitlesController::class, 'index']);
        Route::get('/getJobTitlesByDepartment', [JobTitlesController::class, 'getByDepartment']);
    });
    Route::middleware(['permission:manage job titles,api'])->group(function () {
        Route::post('/addJobTitle', [JobTitlesController::class, 'store']);
        Route::post('/updateJobTitle', [JobTitlesController::class, 'update']);
        Route::delete('/deleteJobTitle', [JobTitlesController::class, 'destroy']);
    });

    // =====================================Tax========================================================
    Route::middleware(['permission:manage taxes,api'])->group(function () {
        Route::get('/getTax', [TaxesController::class, 'index']);
        Route::post('/addTax', [TaxesController::class, 'store']);
        Route::post('/updateTax', [TaxesController::class, 'update']);
        Route::delete('/deleteTax', [TaxesController::class, 'destroy']);
    });

    // =====================================PeakDaysCheck===============================================
    Route::middleware(['permission:manage peak days,api'])->group(function () {
        Route::get('/getPeakDays', [PeakDaysController::class, 'index']);
        Route::post('/updatePeakDaysCheck', [PeakDaysController::class, 'updateCheck']);
    });
    Route::middleware(['permission:manage peak months,api'])->group(function () {
        Route::get('/getPeakMonths', [PeakMonthsController::class, 'index']);
        Route::post('/updatePeakMonthsCheck', [PeakMonthsController::class, 'updateCheck']);
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

    // =====================================Clients====================================================
    Route::middleware(['permission:view clients,api'])->group(function () {
        Route::get('/getClient', [ClientsController::class, 'index']);
        Route::get('/getClient/{id}', [ClientsController::class, 'show']);
        Route::get('/getClientById/{id}', [ClientsController::class, 'getClientById']);
        Route::get('/getClientBy', [ClientsController::class, 'getBy']);
    });
    Route::middleware(['permission:manage clients,api'])->group(function () {
        Route::post('/addClient', [ClientsController::class, 'store']);
        Route::post('/updateClient', [ClientsController::class, 'update']);
    });

    // =====================================Department=================================================
    Route::middleware(['role_or_permission:view users|manage departments,api'])->group(function () {
        Route::get('/getDepartment', [DepartmentsController::class, 'index']);
        Route::get('/getDepartmentById', [DepartmentsController::class, 'show']);
    });
    Route::middleware(['permission:manage departments,api'])->group(function () {
        Route::post('/addDepartment', [DepartmentsController::class, 'store']);
        Route::post('/updateDepartment', [DepartmentsController::class, 'update']);
        Route::delete('/deleteDepartment', [DepartmentsController::class, 'destroy']);
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
    });

    Route::middleware(['permission:cancel reservations,api'])->group(function () {
        Route::post('reservations/{id}/cancel', [ReservationController::class, 'cancel']);
    });

    Route::middleware(['permission:view earnings,api'])->group(function () {
        Route::get('all-earnings', [EarningController::class, 'allEarnings']);
        Route::get('earnings-list', [EarningController::class, 'earningsList']);
        Route::get('earnings-summary', [EarningController::class, 'earningsSummary']);
        Route::get('payments', [EarningController::class, 'payments']);
        Route::get('refunds', [EarningController::class, 'refunds']);
    });

    Route::middleware(['permission:view reports,api'])->group(function () {
        Route::get('reports/catalog', [ReportController::class, 'catalog']);
        Route::get('reports/exports', [ReportExportController::class, 'index']);
        Route::get('reports/{slug}/download', [ReportExportController::class, 'downloadNow']);
        Route::post('reports/{slug}/email-request', [ReportExportController::class, 'requestEmail']);
        Route::get('reports/{slug}', [ReportController::class, 'run']);
    });

    Route::middleware(['permission:view accounting reports,api'])->group(function () {
        Route::get('accounting/chart-of-accounts', [AccountingController::class, 'chartOfAccounts']);
        Route::get('accounting/journal-entries', [AccountingController::class, 'journalEntries']);
    });

    Route::middleware(['permission:manage journal entries,api'])->group(function () {
        Route::post('accounting/journal-entries', [AccountingController::class, 'storeJournalEntry']);
    });

    Route::middleware(['permission:close accounting period,api'])->group(function () {
        Route::post('accounting/periods/close', [AccountingController::class, 'closePeriod']);
    });

    Route::middleware(['permission:view revenue,api', 'permission:view earnings,api'])->group(function () {
        Route::get('financials/bounds', [FinancialDashboardController::class, 'bounds']);
        Route::get('financials/dashboard', [FinancialDashboardController::class, 'show']);
    });

    Route::middleware(['permission:view revenue,api'])->group(function () {
        Route::get('revenue/total', [RevenueController::class, 'getTotalRevenue']);
        Route::get('revenue/room/{entity_id}', [RevenueController::class, 'getRoomRevenue']);
        Route::get('revenue/suite/{entity_id}', [RevenueController::class, 'getSuiteRevenue']);
        Route::get('revenue/floor/{entity_id}', [RevenueController::class, 'getFloorRevenue']);
        Route::get('revenue/building/{entity_id}', [RevenueController::class, 'getBuildingRevenue']);
        Route::get('revenue/roomtype/{entity_id}', [RevenueController::class, 'getRoomTypeRevenue']);
    });

    Route::middleware(['permission:manage refunds,api'])->group(function () {
        Route::post('refund', [ReservationController::class, 'refund']);
        Route::get('refund-policies/preview', [RefundPolicyController::class, 'preview']);
    });

    Route::middleware(['permission:manage refund policies,api'])->group(function () {
        Route::apiResource('refund-policies', RefundPolicyController::class);
        Route::delete('refund-policies', [RefundPolicyController::class, 'destroy']);
    });

    // Report runners need mail_configured without requiring "view users".
    Route::middleware(['permission:view users|view reports,api'])->get('system/health', [SystemHealthController::class, 'index']);

    Route::middleware(['permission:manage client notes,api'])->group(function () {
        Route::get('client-notes', [ClientNoteController::class, 'index']);
        Route::post('client-notes', [ClientNoteController::class, 'store']);
        Route::put('client-notes', [ClientNoteController::class, 'update']);
        Route::delete('client-notes', [ClientNoteController::class, 'destroy']);
    });

    Route::middleware(['permission:manage users,api'])->group(function () {
        Route::post('addUser', [UsersController::class, 'store']);
        Route::post('updateUser', [UsersController::class, 'update']);
        Route::post('inActive', [UsersController::class, 'inActive']);
        Route::get('getInfoUsers', [UsersController::class, 'getInfoUsers']);
    });

    Route::middleware(['permission:manage roles,api'])->group(function () {
        Route::get('getRoles', [RolesController::class, 'index']);
        Route::post('addRole', [RolesController::class, 'store']);
        Route::post('updateRole', [RolesController::class, 'update']);
        Route::post('deleteRole', [RolesController::class, 'destroy']);
        Route::post('assignRole', [RolesController::class, 'assignRole']);
        Route::post('bulkAssignRole', [RolesController::class, 'bulkAssignRole']);
    });

    Route::post('changePassword', [UsersController::class, 'changePassword']);
    Route::get('me', [UsersController::class, 'me']);

});
