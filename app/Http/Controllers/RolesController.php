<?php

namespace App\Http\Controllers;

use App\Services\Cache\CacheInvalidator;
use App\Services\Cache\ReferenceDataCache;
use App\Services\PropertyPermissionBundle;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use App\Models\User;

class RolesController extends Controller
{
    public function index()
    {
        try {
            $roles = ReferenceDataCache::remember(
                'roles',
                'with_permissions',
                fn () => Role::with('permissions')->get()
            );

            return SuccessData('Roles retrieved successfully', $roles);
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|unique:roles,name',
                'name_ar' => 'nullable',
                'permissions' => 'required|array'
            ]);

            $role = Role::create([
                'name' => $request->name,
                'name_ar' => $request->name_ar ?? $request->name,
                'description' => $request->description,
                'guard_name' => 'api'
            ]);

            $permissions = PropertyPermissionBundle::expand($request->permissions);

            $role->syncPermissions($permissions);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            CacheInvalidator::rbacChanged();

            return SuccessData('Role created successfully', $role->load('permissions'));
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function update(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required|exists:roles,id',
                'name' => 'required|unique:roles,name,' . $request->id,
                'name_ar' => 'nullable',
                'permissions' => 'required|array'
            ]);

            $role = Role::findOrFail($request->id);
            $role->update([
                'name' => $request->name,
                'name_ar' => $request->name_ar ?? $request->name,
                'description' => $request->description,
            ]);

            $permissions = PropertyPermissionBundle::expand($request->permissions);

            $role->syncPermissions($permissions);
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            CacheInvalidator::rbacChanged();

            return SuccessData('Role updated successfully', $role->load('permissions'));
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function destroy(Request $request)
    {
        try {
            $role = Role::findOrFail($request->id);

            if ($role->name === 'admin') {
                return Failed('Cannot delete admin role');
            }

            $role->delete();
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            CacheInvalidator::rbacChanged();

            return Success('Role deleted successfully');
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function assignRole(Request $request)
    {
        try {
            $request->validate([
                'userId' => 'required|exists:users,id',
                'roleId' => 'required|exists:roles,id'
            ]);

            $user = User::findOrFail($request->userId);
            $role = Role::findOrFail($request->roleId);

            $user->syncRoles([$role->name]);
            $user->forgetCachedPermissions();
            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            return Success('Role assigned successfully');
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }

    public function bulkAssignRole(Request $request)
    {
        try {
            $request->validate([
                'userIds' => 'required|array',
                'userIds.*' => 'exists:users,id',
                'roleId' => 'required|exists:roles,id'
            ]);

            $role = Role::findOrFail($request->roleId);
            $users = User::whereIn('id', $request->userIds)->get();

            foreach ($users as $user) {
                $user->syncRoles([$role->name]);
                $user->forgetCachedPermissions();
            }

            app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

            return Success('Roles assigned successfully to ' . $users->count() . ' users');
        } catch (\Exception $e) {
            return Failed($e->getMessage());
        }
    }
}
