<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\User;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Requests\Permission\DeletePermissionRequest;
use App\Http\Requests\Permission\AddUserPermissionRequest;
use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;

class PermissionsController extends Controller
{
    public function index()
    {
        try {
            $permissions = ReferenceDataCache::remember(
                'permissions',
                'with_category',
                fn () => Permission::with('category')->get()
            );

            return \SuccessData('Permissions retrieved successfully', $permissions);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function update(UpdatePermissionRequest $request)
    {
        try {
            $permission = Permission::findOrFail($request->id);

            $permission->update([
                'name_ar' => $request->name_ar ?? $permission->name_ar,
                'description_en' => $request->description_en ?? $permission->description_en,
                'description_ar' => $request->description_ar ?? $permission->description_ar,
                'category_id' => $request->category_id ?? $permission->category_id,
            ]);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            CacheInvalidator::rbacChanged();

            return \SuccessData('Permission updated successfully', $permission->load('category'));
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function destroy(DeletePermissionRequest $request)
    {
        try {
            $permission = Permission::findOrFail($request->id);
            $permission->delete();
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            CacheInvalidator::rbacChanged();

            return \Success('Permission deleted successfully');
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }

    public function addUserPermission(AddUserPermissionRequest $request)
    {
        try {
            $user = User::findOrFail($request->user_id);
            $permissionNames = Permission::whereIn('id', $request->permission_ids)->pluck('name')->all();
            $user->syncPermissions($permissionNames);
            $user->load(['permissions', 'roles']);

            return \SuccessData('User permissions updated successfully', $user);
        } catch (\Exception $e) {
            return \Failed($e->getMessage());
        }
    }
}
