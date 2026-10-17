<?php

use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\HotelsController;
use App\Http\Controllers\JobTitlesController;
use App\Http\Controllers\PermissionsController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

// =====================================Hotels (branches)===========================================
Route::middleware(['permission:manage hotels,api'])->group(function () {
    Route::get('/getHotels', [HotelsController::class, 'index']);
    Route::post('/addHotel', [HotelsController::class, 'store']);
    Route::post('/updateHotel/{id}', [HotelsController::class, 'update']);
    Route::get('/hotels/{id}/users', [HotelsController::class, 'users']);
    Route::post('/hotels/{id}/users', [HotelsController::class, 'assignUser']);
    Route::delete('/hotels/{id}/users/{userId}', [HotelsController::class, 'removeUser']);
});

// Staff/access-control domain: departments, job titles, permissions, users and
// roles, plus the authenticated user's own account actions. Split out of
// routes/users.php — same routes, same "api/users" prefix, unchanged.

// =====================================Permissions=================================================
Route::middleware(['permission:manage permissions,api'])->group(function () {
    Route::get('/getPermissions', [PermissionsController::class, 'index']);
    Route::post('/updatePermission', [PermissionsController::class, 'update']);
    Route::delete('/deletePermission', [PermissionsController::class, 'destroy']);
    Route::post('/addUserPermission', [PermissionsController::class, 'addUserPermission']);
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

// =====================================Users/Roles=================================================
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

// =====================================Own account=================================================
Route::post('changePassword', [UsersController::class, 'changePassword']);
Route::get('me', [UsersController::class, 'me']);
